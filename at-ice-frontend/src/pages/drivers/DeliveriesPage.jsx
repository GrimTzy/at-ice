import { useState, useEffect, useCallback, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { MemoAlert } from "../../components/MyMemos";
import MobileHeader from "../../components/MobileHeader";
import { SearchBar } from "../../components/MobileUi";
import { matchesQuery } from "../../lib/mobileUi";
import echo from "../../echo";
import { SkeletonList } from "../../components/Skeleton";
import { toList } from "../../lib/list";
import { useLiveTick } from "../../lib/live";
import EmptyState from "../../components/EmptyState";
import { useTimedIn } from "../../lib/timeIn";
import TimeInNotice from "../../components/TimeInNotice";
import Pagination from "../../components/Pagination";
import { usePagination } from "../../lib/usePagination";
import { StopMeta, ArrivalChips, RouteOrderNote } from "../../components/RouteParts";
import { pressArrived } from "../../lib/route";
import { toast } from "../../lib/toast";
import FilterSelect from "../../components/FilterSelect";
import { Icon } from "../../components/icons";
import { useNoticeRecord } from "../../lib/noticeRecord";

// Haversine formula, gives straight line distance in km between two lat/lng points
function haversineDistanceKm(lat1, lon1, lat2, lon2) {
  if (lat1 == null || lon1 == null || lat2 == null || lon2 == null) return null;
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLon / 2) ** 2;
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

// Icons
const MapPinIcon = () => (
  <Icon name="mapPin" size={14} />
);
const UserIcon = () => (
  <Icon name="user" size={13} />
);
const PhoneIcon = () => (
  <Icon name="phone" size={13} />
);
const PackageIcon = () => (
  <Icon name="package" size={13} />
);
const ChevronRightIcon = () => (
  <Icon name="chevronRight" size={15} />
);
const ClockIcon = () => (
  <Icon name="clock" size={12} />
);

const STATUS_CONFIG = {
  pending:    { bg: "bg-amber-50",  text: "text-amber-600",  dot: "bg-amber-400",  label: "Pending"    },
  in_transit: { bg: "bg-blue-50",   text: "text-blue-600",   dot: "bg-blue-500",   label: "In Transit" },
  delivered:  { bg: "bg-green-50",  text: "text-green-600",  dot: "bg-green-500",  label: "Delivered"  },
};

const FILTERS = [
  { key: "all",        label: "All" },
  { key: "pending",    label: "Pending" },
  { key: "in_transit", label: "In Transit" },
  { key: "delivered",  label: "Delivered" },
];

export function DeliveriesPage() {
  const navigate = useNavigate();
  const [filter, setFilter] = useState("all");
  const [query, setQuery] = useState("");
  const [expanded, setExpanded] = useState(null);
  const [deliveries, setDeliveries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [driverPos, setDriverPos] = useState(null);
  const [actionLoading, setActionLoading] = useState(null);
  const [salesmen, setSalesmen] = useState([]);
  const [radius, setRadius] = useState(150);
  const timedIn = useTimedIn();
  const watchIdRef = useRef(null);

  const user = JSON.parse(localStorage.getItem("user") || "{}");

  const fetchDeliveries = useCallback(async () => {
    setLoading(true);
    try {
      const token = localStorage.getItem("token");
      const res = await fetch("/api/driver/deliveries", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      // today's route first (nearest the plant first), then later stops
      setDeliveries(Array.isArray(data) ? data : toList(data.deliveries));
      setSalesmen(data.salesmen || []);
      if (data.radius_m) setRadius(data.radius_m);
    } catch (err) {
      console.error("Failed to load deliveries:", err);
      setDeliveries([]);
    } finally {
      setLoading(false);
    }
  }, []);

  // reload when a live notice for this page arrives (lib/live.js)
  const live = useLiveTick(["deliveries"]);
  useEffect(() => {
    fetchDeliveries();
  }, [fetchDeliveries, live]);

  // Live GPS position, used only to compute an approximate distance to each stop
  useEffect(() => {
    if (!navigator.geolocation) return;
    watchIdRef.current = navigator.geolocation.watchPosition(
      (pos) => setDriverPos({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => setDriverPos(null),
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
    );
    return () => {
      if (watchIdRef.current !== null) navigator.geolocation.clearWatch(watchIdRef.current);
    };
  }, []);

  // Real time updates via Pusher, on the public "deliveries" channel, event "delivery.status.updated"
  // Only apply updates that belong to this driver's own deliveries
  useEffect(() => {
    const channel = echo.private("deliveries");
    channel.listen(".delivery.status.updated", (e) => {
      if (String(e.driver_id) !== String(user?.id)) return;
      setDeliveries((prev) =>
        prev.map((d) => (d.id === e.delivery_id ? { ...d, status: e.status } : d))
      );
    });
    return () => {
      echo.leave("deliveries");
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Driver can only start a delivery. "Delivered" is confirmed by the Salesman.
  async function startDelivery(deliveryId) {
    setActionLoading(deliveryId);
    try {
      const token = localStorage.getItem("token");
      const res = await fetch(`/api/driver/deliveries/${deliveryId}/status`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ status: "in_transit" }),
      });
      if (!res.ok) {
        const errData = await res.json().catch(() => null);
        toast.error(errData?.message || "Could not start this delivery. Please try again.");
        return;
      }
      await res.json();
      fetchDeliveries();
    } catch {
      toast.error("Could not reach the server. Check your connection and try again.");
    } finally {
      setActionLoading(null);
    }
  }

  const visible = (filter === "all" ? deliveries : deliveries.filter((d) => d.status === filter)).filter((d) =>
    matchesQuery(query, d.delivery_no, d.customer?.name, d.customer?.address, d.customer?.city, d.customer?.province));
  const pager = usePagination(visible, { initialSize: 10 });
  useNoticeRecord(!loading, (record) => {
    const [kind, id] = record.split(":");
    if (kind !== "delivery") return;
    setQuery(""); setFilter("all");
    pager.reveal((row) => String(row.id) === id);
  });

  // I am at the store: the server checks the phone is near the store pin
  async function arrive(d) {
    setActionLoading(d.id);
    try {
      const res = await pressArrived(`/api/driver/deliveries/${d.id}/arrive`);
      toast.success(res.message || "Arrived.");
      fetchDeliveries();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setActionLoading(null);
    }
  }

  const counts = {
    total:      deliveries.length,
    pending:    deliveries.filter((d) => d.status === "pending").length,
    in_transit: deliveries.filter((d) => d.status === "in_transit").length,
    delivered:  deliveries.filter((d) => d.status === "delivered").length,
  };

  const SUMMARY = [
    { label: "Total",      value: counts.total,      color: "text-slate-800" },
    { label: "Pending",    value: counts.pending,    color: "text-amber-500" },
    { label: "In Transit", value: counts.in_transit, color: "text-blue-500"  },
    { label: "Delivered",  value: counts.delivered,  color: "text-green-500" },
  ];

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col font-sans">
      <MobileHeader role="driver" title="My Deliveries">
        <div className="px-4 pt-1 pb-3">
          <MemoAlert />
          <div className="grid grid-cols-4 gap-2 mt-2 pill-row">
            {SUMMARY.map((s) => {
              // Tap a count to show only those deliveries (same as the tabs below)
              const key = s.label === "Total" ? "all" : FILTERS.find((f) => f.label === s.label)?.key;
              return (
                <button
                  key={s.label}
                  type="button"
                  onClick={() => setFilter(key)}
                  title={`Show ${s.label === "Total" ? "all" : s.label.toLowerCase()} deliveries`}
                  className={`stat-pill bg-slate-50 border border-black/[0.08] rounded-xl px-1.5 py-2 text-center cursor-pointer min-w-0 ${filter === key ? "is-active" : ""}`}
                >
                  <div className={`text-[16px] font-semibold font-mono leading-none ${s.color}`}>{s.value}</div>
                  <div className="text-[11px] text-slate-400 mt-1 tracking-wide whitespace-nowrap">{s.label}</div>
                </button>
              );
            })}
          </div>
        </div>
      </MobileHeader>

      {/* Filter tabs */}
      <div className="px-4 pt-4 flex-shrink-0">
        <div className="grid gap-3">
          <SearchBar value={query} onChange={setQuery} placeholder="Search store, delivery no. or address" label="Search deliveries" />
          <FilterSelect label="Status" value={filter} onChange={setFilter} className="w-full [&>select]:flex-1"
            options={FILTERS.map((f) => ({ value: f.key, label: f.label, count: counts[f.key === "all" ? "total" : f.key] }))} />
        </div>
      </div>

      {/* Delivery list */}
      <div className="flex-1 px-4 py-4 flex flex-col gap-3 overflow-y-auto pb-56">
        <TimeInNotice timedIn={timedIn} />
        {salesmen.length > 0 && (
          <div className="bg-white border border-black/[0.08] rounded-2xl px-4 py-3 text-[12px] text-slate-600">
            Salesman with you today: <span className="font-semibold text-slate-800">{salesmen.map((s) => s.name).join(", ")}</span>
          </div>
        )}
        {loading && (
          <SkeletonList />
        )}
        {!loading && visible.length === 0 && (
          <EmptyState icon="truck">No deliveries found.</EmptyState>
        )}
        {!loading && visible.some((d) => d.route_today && d.stop_no) && <RouteOrderNote />}
        {!loading && pager.pageItems.map((d) => {
          const cfg = STATUS_CONFIG[d.status] || STATUS_CONFIG.pending;
          const open = expanded === d.id;
          const customer = d.customer;
          const destination = customer
            ? [customer.address, customer.city, customer.province].filter(Boolean).join(", ")
            : "—";
          const itemsSummary = d.items?.length
            ? d.items.map((i) => `${i.product_label ?? i.product?.name ?? "Item"} × ${i.quantity_ordered}`).join(", ")
            : "—";
          const distanceKm = customer
            ? haversineDistanceKm(driverPos?.lat, driverPos?.lng, customer.latitude, customer.longitude)
            : null;

          return (
            <div key={d.id} className="bg-white border border-black/[0.08] rounded-2xl overflow-hidden shadow-sm">

              <button
                className="w-full text-left px-4 py-4 flex items-start gap-3 cursor-pointer active:bg-slate-50 transition-colors"
                onClick={() => setExpanded(open ? null : d.id)}
                data-record={`delivery:${d.id}`}
              >
                <div className="mt-1 flex-shrink-0">
                  <span className={`w-2 h-2 rounded-full inline-block ${cfg.dot}`} />
                </div>
                <div className="flex-1 min-w-0">
                  {d.route_today && <StopMeta delivery={d} className="mb-1.5" />}
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-[13px] font-semibold text-slate-800 truncate">{customer?.name || "Unknown Customer"}</span>
                    <span className={`flex-shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full ${cfg.bg} ${cfg.text}`}>
                      {cfg.label}
                    </span>
                  </div>
                  <div className="flex items-center gap-1 mt-1 text-[11px] text-slate-400">
                    <MapPinIcon />
                    <span className="truncate">{destination}</span>
                  </div>
                  <div className="flex items-center gap-3 mt-1.5 flex-wrap">
                    <span className="text-[10px] text-slate-400 flex items-center gap-1">
                      <ClockIcon />
                      {new Date(d.scheduled_date).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}
                    </span>
                    {distanceKm != null && (
                      <>
                        <span className="text-[10px] text-slate-300">·</span>
                        <span className="text-[10px] text-slate-400">{distanceKm.toFixed(1)} km from you</span>
                      </>
                    )}
                    <span className="text-[10px] text-slate-300">·</span>
                    <span className="text-[10px] font-mono text-slate-400">{d.delivery_no}</span>
                  </div>
                  {d.reassigned_from && (
                    <span className="inline-block mt-1.5 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                      Reassigned from {d.reassigned_from.name}
                    </span>
                  )}
                </div>
                <span className={`text-slate-300 flex-shrink-0 mt-1 transition-transform duration-200 ${open ? "rotate-90" : ""}`}>
                  <ChevronRightIcon />
                </span>
              </button>

              {open && (
                <div className="border-t border-black/[0.07] px-4 py-4 flex flex-col gap-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Customer</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <UserIcon />{customer?.name || "—"}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Contact</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-cyan-500 font-medium">
                        <PhoneIcon />{customer?.contact_number || "—"}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5 col-span-2">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Destination</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <MapPinIcon />{destination}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5 col-span-2">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Items</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <PackageIcon />{itemsSummary}
                      </div>
                    </div>
                  </div>

                  {d.status === "pending" && (
                    <div className="flex gap-2 mt-1">
                      <button
                        onClick={() => startDelivery(d.id)}
                        disabled={actionLoading === d.id || timedIn === false}
                        className="flex-1 py-3 bg-cyan-500 active:bg-cyan-600 text-white text-[12px] font-semibold rounded-xl transition-colors cursor-pointer disabled:opacity-60"
                      >
                        {actionLoading === d.id ? "Updating…" : "Start Delivery"}
                      </button>
                      <button
                        onClick={() => navigate("/driver/gps")}
                        className="flex-1 py-3 bg-slate-100 active:bg-slate-200 text-slate-600 text-[12px] font-semibold rounded-xl transition-colors cursor-pointer"
                      >
                        View on Map
                      </button>
                    </div>
                  )}
                  {d.status === "in_transit" && (
                    <div className="flex flex-col gap-2 mt-1">
                      <div className="flex items-center gap-2 bg-cyan-50 border border-cyan-100 rounded-xl px-3 py-2.5">
                        <span className="w-2 h-2 rounded-full bg-cyan-600 flex-shrink-0" />
                        <span className="text-[12px] text-cyan-800 font-medium">
                          On the way. At the store, you and the Salesman both press Arrived; then the Salesman confirms the delivery.
                        </span>
                      </div>
                      <ArrivalChips arrival={d.arrival} driverLabel="You" />
                      {!d.arrival?.driver && (
                        <button
                          onClick={() => arrive(d)}
                          disabled={actionLoading === d.id || timedIn === false}
                          data-no-confirm
                          data-tip={`Shares your location. You must be within ${radius} m of the store.`}
                          className="w-full py-3 bg-cyan-500 active:bg-cyan-600 text-white text-[12px] font-semibold rounded-xl transition-colors cursor-pointer disabled:opacity-60"
                        >
                          {actionLoading === d.id ? "Checking your location…" : "I am at the store"}
                        </button>
                      )}
                      <button
                        onClick={() => navigate("/driver/gps")}
                        className="w-full py-3 bg-slate-100 active:bg-slate-200 text-slate-600 text-[12px] font-semibold rounded-xl transition-colors cursor-pointer"
                      >
                        View on Map
                      </button>
                    </div>
                  )}
                  {d.status === "delivered" && (
                    <div className="flex items-center gap-2 bg-green-50 border border-green-100 rounded-xl px-3 py-2.5">
                      <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                      <span className="text-[12px] text-green-600 font-medium">Delivery completed successfully.</span>
                    </div>
                  )}
                </div>
              )}
            </div>
          );
        })}
        {!loading && visible.length > 0 && (
          <div className="rounded-2xl overflow-hidden"><Pagination pager={pager} label="stops" fixed dock dockBottom="0px" dockPad="6.25rem" /></div>
        )}
      </div>

      {/* The map, one tap away from anywhere in the list: a round raised button
          floating at the bottom center (it was a small button in the top bar) */}
      <button
        type="button"
        onClick={() => navigate("/driver/gps")}
        title="Open the GPS map"
        aria-label="Maps: open the GPS map"
        data-no-confirm
        className="maps-fab"
      >
        <Icon name="map" size={22} />
        <span>Maps</span>
      </button>
    </div>
  );
}
