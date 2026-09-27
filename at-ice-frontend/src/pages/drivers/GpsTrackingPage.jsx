import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

// Icons (unchanged)
const LogOutIcon = () => (
  <svg width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
    <polyline points="16 17 21 12 16 7" />
    <line x1="21" y1="12" x2="9" y2="12" />
  </svg>
);
const MapIcon = () => (
  <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" />
    <line x1="8" y1="2" x2="8" y2="18" />
    <line x1="16" y1="6" x2="16" y2="22" />
  </svg>
);
const NavIcon = () => (
  <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polygon points="3 11 22 2 13 21 11 13 3 11" />
  </svg>
);
const ChevronUpIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polyline points="18 15 12 9 6 15" />
  </svg>
);
const ChevronDownIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polyline points="6 9 12 15 18 9" />
  </svg>
);
const MapPinIcon = () => (
  <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
    <circle cx="12" cy="10" r="3" />
  </svg>
);
const PackageIcon = () => (
  <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
  </svg>
);
const BackIcon = () => (
  <svg width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polyline points="15 18 9 12 15 6" />
  </svg>
);

// Backend status -> display label + colors. Panel shows ALL stops (Option 1),
// including "delivered" ones.
const STATUS_CONFIG = {
  pending:    { label: "Pending",    bg: "bg-amber-50",  text: "text-amber-600", dot: "bg-amber-400", markerFill: "#ef4444", markerStroke: "#dc2626", markerLabel: "!" },
  in_transit: { label: "In Transit", bg: "bg-blue-50",   text: "text-blue-600",  dot: "bg-blue-500",  markerFill: "#3b82f6", markerStroke: "#2563eb", markerLabel: "!" },
  delivered:  { label: "Delivered",  bg: "bg-green-50",  text: "text-green-600", dot: "bg-green-500", markerFill: "#22c55e", markerStroke: "#16a34a", markerLabel: "✓" },
  cancelled:  { label: "Cancelled",  bg: "bg-slate-100", text: "text-slate-400", dot: "bg-slate-300", markerFill: "#94a3b8", markerStroke: "#64748b", markerLabel: "×" },
};

function itemsSummary(delivery) {
  if (!Array.isArray(delivery.items) || delivery.items.length === 0) return "No items listed";
  return delivery.items
    .map((it) => `${it.product?.name ?? "Item"} × ${it.quantity_ordered}`)
    .join(", ");
}

export function GpsTrackingPage() {
  const navigate = useNavigate();
  const mapRef         = useRef(null);
  const mapInstanceRef = useRef(null);
  const markersRef     = useRef([]);
  const driverDotRef   = useRef(null);
  const watchIdRef     = useRef(null);
  const infoWindowRef  = useRef(null);
  const echoChannelRef = useRef(null);

  const [driverPos, setDriverPos]         = useState(null);
  const [gpsStatus, setGpsStatus]         = useState("waiting");
  const [panelOpen, setPanelOpen]         = useState(true);
  const [selectedStore, setSelectedStore] = useState(null);
  const [stores, setStores]               = useState([]);
  const [loading, setLoading]             = useState(true);

  const user = JSON.parse(localStorage.getItem("user") || "{}");
  const token = localStorage.getItem("token");

  const pending   = stores.filter((s) => s.status === "pending" || s.status === "in_transit").length;
  const delivered = stores.filter((s) => s.status === "delivered").length;

  // ── Fetch real deliveries (same endpoint DeliveriesPage.jsx uses) ─────────
  useEffect(() => {
    fetch("/api/driver/deliveries", {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
    })
      .then((res) => res.json())
      .then((data) => {
        const list = Array.isArray(data) ? data : data.deliveries ?? [];
        setStores(list);
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, [token]);

  // Re-plot markers whenever the delivery list changes (initial load or a
  // Pusher update below).
  useEffect(() => {
    if (mapInstanceRef.current) plotStoreMarkers(mapInstanceRef.current);
  }, [stores]);

  // ── Live status updates via Pusher/Echo (same channel/event as
  // DeliveriesPage.jsx: public "deliveries" channel, "delivery.status.updated") ──
  useEffect(() => {
    if (!window.Echo) return;
    const channel = window.Echo.channel("deliveries");
    echoChannelRef.current = channel;

    channel.listen(".delivery.status.updated", (e) => {
      if (e.driver_id !== user?.id) return;
      setStores((prev) =>
        prev.map((s) => (s.id === e.delivery_id ? { ...s, status: e.status } : s))
      );
    });

    return () => {
      window.Echo.leaveChannel("deliveries");
    };
  }, [user?.id]);

  // Load Google Maps 
  useEffect(() => {
  if (window.google) { initMap(); return; }
  const existing = document.getElementById("google-maps-script");
  if (existing) {
    existing.addEventListener("load", initMap, { once: true });
    return;
  }
  window.initMap = initMap;
  const script = document.createElement("script");
  script.id = "google-maps-script";
  script.src = `https://maps.googleapis.com/maps/api/js?key=${import.meta.env.VITE_GOOGLE_MAPS_API_KEY}&callback=initMap`;
  script.async = true;
  script.defer = true;
  document.head.appendChild(script);
}, []);

  function initMap() {
    if (!mapRef.current) return;
    const center = { lat: 14.6600, lng: 121.0000 };
    const map = new window.google.maps.Map(mapRef.current, {
      center,
      zoom: 13,
      disableDefaultUI: true,
      zoomControl: true,
      zoomControlOptions: { position: window.google.maps.ControlPosition.RIGHT_CENTER },
      styles: [
        { featureType: "poi",          elementType: "labels", stylers: [{ visibility: "off" }] },
        { featureType: "transit",      elementType: "labels", stylers: [{ visibility: "off" }] },
        { featureType: "water",        elementType: "geometry", stylers: [{ color: "#bfdbfe" }] },
        { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#f1f5f9" }] },
        { featureType: "landscape",    elementType: "geometry", stylers: [{ color: "#f8fafc" }] },
      ],
    });
    mapInstanceRef.current = map;
    plotStoreMarkers(map);
    startGpsTracking(map);
  }

  function plotStoreMarkers(map) {
    markersRef.current.forEach((m) => m.setMap(null));
    markersRef.current = [];
    if (infoWindowRef.current) infoWindowRef.current.close();
    infoWindowRef.current = new window.google.maps.InfoWindow();

    stores.forEach((store) => {
      if (store.customer?.latitude == null || store.customer?.longitude == null) return;
      const cfg = STATUS_CONFIG[store.status] ?? STATUS_CONFIG.pending;

      const marker = new window.google.maps.Marker({
        position: { lat: Number(store.customer.latitude), lng: Number(store.customer.longitude) },
        map,
        title: store.customer?.name,
        icon: {
          path: window.google.maps.SymbolPath.CIRCLE,
          scale: 10,
          fillColor: cfg.markerFill,
          fillOpacity: 1,
          strokeColor: cfg.markerStroke,
          strokeWeight: 2,
        },
        label: {
          text: cfg.markerLabel,
          color: "#ffffff",
          fontSize: "10px",
          fontWeight: "bold",
        },
      });

      marker.addListener("click", () => {
        infoWindowRef.current.setContent(`
          <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:12px;padding:4px 2px;min-width:160px;">
            <div style="font-weight:600;color:#1e293b;margin-bottom:4px;">${store.customer?.name ?? "Customer"}</div>
            <div style="color:#64748b;margin-bottom:2px;">${store.customer?.address ?? ""}</div>
            <div style="color:#64748b;">${itemsSummary(store)}</div>
            <div style="margin-top:6px;display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:600;
              background:${cfg.bg === "bg-green-50" ? "#dcfce7" : "#fef3c7"};
              color:${cfg.markerStroke};">
              ${cfg.label}
            </div>
          </div>`);
        infoWindowRef.current.open(map, marker);
        setSelectedStore(store);
      });

      markersRef.current.push(marker);
    });
  }

  function startGpsTracking(map) {
    if (!navigator.geolocation) { setGpsStatus("error"); return; }
    watchIdRef.current = navigator.geolocation.watchPosition(
      (pos) => {
        const { latitude: lat, longitude: lng } = pos.coords;
        setDriverPos({ lat, lng });
        setGpsStatus("active");
        if (driverDotRef.current) {
          driverDotRef.current.setPosition({ lat, lng });
        } else {
          driverDotRef.current = new window.google.maps.Marker({
            position: { lat, lng },
            map,
            title: "Your Location",
            icon: {
              path: window.google.maps.SymbolPath.CIRCLE,
              scale: 9,
              fillColor: "#06b6d4",
              fillOpacity: 1,
              strokeColor: "#0e7490",
              strokeWeight: 2,
            },
            zIndex: 999,
          });
          map.panTo({ lat, lng });
        }
      },
      () => setGpsStatus("error"),
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
    );
  }

  function centerOnDriver() {
    if (driverPos && mapInstanceRef.current) {
      mapInstanceRef.current.panTo(driverPos);
      mapInstanceRef.current.setZoom(15);
    }
  }

  useEffect(() => {
    return () => {
      if (watchIdRef.current !== null) navigator.geolocation.clearWatch(watchIdRef.current);
    };
  }, []);

  const todayLabel = new Date().toLocaleDateString(undefined, {
    weekday: "long", year: "numeric", month: "long", day: "numeric",
  });

  return (
    <div className="min-h-screen flex flex-col font-sans bg-slate-50">

      {/* ── Top Bar ── */}
      <header className="bg-white border-b border-black/[0.08] px-5 pt-10 pb-4 flex-shrink-0 sticky top-0 z-20">
        <div className="flex items-center justify-between mb-1">
          <div className="flex items-center gap-2">
            <button
              onClick={() => navigate("/driver/deliveries")}
              className="text-slate-400 active:text-slate-600 transition-colors cursor-pointer p-1 -ml-1 mr-1"
            >
              <BackIcon />
            </button>
            <div className="w-7 h-7 bg-cyan-500/10 border border-cyan-500/20 rounded-lg flex items-center justify-center overflow-hidden">
              <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1" />
            </div>
            <div>
              <div className="text-[13px] font-semibold text-slate-800 leading-none">{user?.name || "Driver"}</div>
              <div className="text-[10px] text-slate-400 tracking-wide">Driver</div>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <div className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-medium border ${
              gpsStatus === "active"  ? "bg-green-50 border-green-200 text-green-600" :
              gpsStatus === "error"   ? "bg-red-50 border-red-200 text-red-500" :
                                        "bg-amber-50 border-amber-200 text-amber-600"
            }`}>
              <span className={`w-1.5 h-1.5 rounded-full ${
                gpsStatus === "active" ? "bg-green-500 animate-pulse" :
                gpsStatus === "error"  ? "bg-red-500" : "bg-amber-400 animate-pulse"
              }`} />
              {gpsStatus === "active" ? "GPS Active" : gpsStatus === "error" ? "GPS Error" : "Acquiring..."}
            </div>
            <button
              onClick={() => navigate("/driver/login")}
              className="text-slate-400 active:text-red-500 transition-colors cursor-pointer p-1"
            >
              <LogOutIcon />
            </button>
          </div>
        </div>

        <div className="mt-3">
          <div className="text-[18px] font-semibold text-slate-800 leading-tight">GPS Tracking</div>
          <div className="text-[12px] text-slate-400 mt-0.5">{todayLabel}</div>
        </div>

        <div className="flex gap-2 mt-3">
          {[
            { label: "Total Stops", value: stores.length, color: "text-slate-800" },
            { label: "Pending",     value: pending,       color: "text-amber-500" },
            { label: "Delivered",   value: delivered,     color: "text-green-500" },
          ].map((s) => (
            <div key={s.label} className="flex-1 bg-slate-50 border border-black/[0.08] rounded-xl px-2 py-2 text-center">
              <div className={`text-[16px] font-semibold font-mono leading-none ${s.color}`}>{s.value}</div>
              <div className="text-[9px] text-slate-400 mt-0.5 tracking-wide">{s.label}</div>
            </div>
          ))}
        </div>
      </header>

      {/* ── Map ── */}
      <div className="relative flex-1" style={{ minHeight: "320px" }}>
        <div ref={mapRef} className="absolute inset-0 w-full h-full" />

        <button
          onClick={centerOnDriver}
          className="absolute top-3 right-3 z-10 w-10 h-10 bg-white border border-black/[0.10] rounded-xl shadow-md flex items-center justify-center text-cyan-500 active:bg-slate-50 transition-colors cursor-pointer"
        >
          <NavIcon />
        </button>

        <div className="absolute top-3 left-3 z-10 bg-white/95 border border-black/[0.08] rounded-xl px-3 py-2 flex flex-col gap-1.5 shadow-sm">
          <div className="flex items-center gap-2 text-[10px] text-slate-600">
            <span className="w-2.5 h-2.5 rounded-full bg-cyan-500 flex-shrink-0" />Your Location
          </div>
          <div className="flex items-center gap-2 text-[10px] text-slate-600">
            <span className="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0" />Pending Stop
          </div>
          <div className="flex items-center gap-2 text-[10px] text-slate-600">
            <span className="w-2.5 h-2.5 rounded-full bg-blue-500 flex-shrink-0" />In Transit
          </div>
          <div className="flex items-center gap-2 text-[10px] text-slate-600">
            <span className="w-2.5 h-2.5 rounded-full bg-green-500 flex-shrink-0" />Delivered
          </div>
        </div>

        {selectedStore && (
          <div className="absolute bottom-0 left-0 right-0 z-20 bg-white border-t border-black/[0.08] rounded-t-2xl shadow-lg px-5 py-4">
            <div className="flex items-start justify-between gap-3">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className={`w-2 h-2 rounded-full flex-shrink-0 ${STATUS_CONFIG[selectedStore.status]?.dot}`} />
                  <span className="text-[13px] font-semibold text-slate-800 truncate">{selectedStore.customer?.name}</span>
                </div>
                <div className="flex items-center gap-1 text-[11px] text-slate-400 mb-0.5">
                  <MapPinIcon />{selectedStore.customer?.address}
                </div>
                <div className="flex items-center gap-1 text-[11px] text-slate-400">
                  <PackageIcon />{itemsSummary(selectedStore)}
                </div>
              </div>
              <div className="flex flex-col items-end gap-2 flex-shrink-0">
                <span className={`text-[10px] font-medium px-2 py-0.5 rounded-full ${STATUS_CONFIG[selectedStore.status]?.bg} ${STATUS_CONFIG[selectedStore.status]?.text}`}>
                  {STATUS_CONFIG[selectedStore.status]?.label}
                </span>
                <button
                  onClick={() => setSelectedStore(null)}
                  className="text-[11px] text-slate-400 active:text-slate-600 cursor-pointer"
                >
                  Dismiss
                </button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* ── Delivery Stops Panel — shows ALL stops, Delivered included (Option 1) ── */}
      <div className={`relative z-20 bg-white border-t border-black/[0.08] shadow-[0_-4px_20px_rgba(0,0,0,0.08)] flex-shrink-0 transition-all duration-300 overflow-hidden ${panelOpen ? "max-h-[280px]" : "max-h-[52px]"}`}>

        <button
          onClick={() => setPanelOpen((v) => !v)}
          className="w-full flex items-center justify-between px-5 py-3.5 cursor-pointer active:bg-slate-50 transition-colors"
        >
          <div className="flex items-center gap-2 text-[13px] font-semibold text-slate-800">
            <MapIcon />
            Delivery Stops
            <span className="text-[11px] font-normal text-slate-400 ml-1">{stores.length} stops today</span>
          </div>
          <span className="text-slate-400">{panelOpen ? <ChevronDownIcon /> : <ChevronUpIcon />}</span>
        </button>

        <div className="overflow-y-auto px-4 pb-6 flex flex-col gap-2" style={{ maxHeight: "220px" }}>
          {loading && (
            <div className="text-[12px] text-slate-400 text-center py-4">Loading deliveries...</div>
          )}
          {!loading && stores.length === 0 && (
            <div className="text-[12px] text-slate-400 text-center py-4">No deliveries scheduled today.</div>
          )}
          {stores.map((store) => {
            const cfg = STATUS_CONFIG[store.status] ?? STATUS_CONFIG.pending;
            return (
              <button
                key={store.id}
                className="w-full text-left bg-slate-50 border border-black/[0.07] rounded-xl px-4 py-3 flex items-center gap-3 active:bg-slate-100 transition-colors cursor-pointer"
                onClick={() => {
                  setSelectedStore(store);
                  if (mapInstanceRef.current && store.customer?.latitude != null) {
                    mapInstanceRef.current.panTo({ lat: Number(store.customer.latitude), lng: Number(store.customer.longitude) });
                    mapInstanceRef.current.setZoom(15);
                  }
                }}
              >
                <span className={`w-2 h-2 rounded-full flex-shrink-0 ${cfg.dot}`} />
                <div className="flex-1 min-w-0">
                  <div className="text-[12px] font-semibold text-slate-800 truncate">{store.customer?.name}</div>
                  <div className="text-[10px] text-slate-400 truncate mt-0.5">{store.customer?.address}</div>
                </div>
                <span className={`text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0 ${cfg.bg} ${cfg.text}`}>
                  {cfg.label}
                </span>
              </button>
            );
          })}
        </div>
      </div>

    </div>
  );
}