import { useEffect, useState, useCallback } from "react";
import { Chart } from "chart.js/auto";
import echo from "../../echo";

// ─── ICONS
const Bolt       = () => <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>;
const GridIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
const UsersIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>;
const ClockIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>;
const FileIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>;
const DollarIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>;
const BoxIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>;
const AlertIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>;
const HomeIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>;
const TruckIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
const CheckIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>;
const UserIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>;
const CartIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>;
const WifiIcon   = () => <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>;
const DbIcon     = () => <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>;
const CalIcon    = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>;
const PulseIcon  = () => <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>;
const ChevDown   = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>;
const ChevRight  = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>;
const ArrowUp    = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>;
const ArrowDown  = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="18 9 12 15 6 9"/></svg>;
const InfoIcon   = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>;
const SettingsIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>;
const LogsIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>;
const ArrowsIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>;
const RolesIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>;
const TrendUpIcon   = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>;
const TrendDownIcon = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>;
const RefreshIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>;

// ─── HELPERS
const fmt  = (n) => "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const pct  = (n) => (n == null ? "N/A" : (n >= 0 ? "+" : "") + n + "%");
const token = () => localStorage.getItem("token") || "";

// ─── MINI LINE CHART ──────────────────────────────────────────────────────────
function MiniChart({ id, data, color }) {
  useEffect(() => {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    const hexToRgba = (hex, a) => {
      const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
      return `rgba(${r},${g},${b},${a})`;
    };
    const chart = new Chart(ctx, {
      type: "line",
      data: { labels: data.map((_,i)=>i), datasets: [{ data, borderColor: color, borderWidth: 1.5, pointRadius: 0, tension: 0.4, fill: true, backgroundColor: (ctx2) => { const g = ctx2.chart.ctx.createLinearGradient(0,0,0,36); g.addColorStop(0,hexToRgba(color,0.18)); g.addColorStop(1,hexToRgba(color,0)); return g; } }] },
      options: { responsive:true, maintainAspectRatio:false, animation:false, plugins:{legend:{display:false},tooltip:{enabled:false}}, scales:{x:{display:false},y:{display:false}} },
    });
    return () => chart.destroy();
  }, [JSON.stringify(data)]);
  return <div className="h-9 -mx-1 -mb-1 mt-1"><canvas id={id} /></div>;
}

// ─── DONUT CHART
function DonutChart({ id, data, colors }) {
  useEffect(() => {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    const chart = new Chart(ctx, {
      type: "doughnut",
      data: { datasets: [{ data, backgroundColor: colors, borderColor:"#ffffff", borderWidth:3, hoverOffset:4 }] },
      options: { cutout:"65%", responsive:true, maintainAspectRatio:true, plugins:{ legend:{display:false}, tooltip:{ backgroundColor:"#fff", borderColor:"rgba(0,0,0,0.12)", borderWidth:1, bodyColor:"#1a2332", padding:8 } } },
    });
    return () => chart.destroy();
  }, [JSON.stringify(data)]);
  return <canvas id={id} className="w-[90px] h-[90px]" />;
}

// ─── SALES BAR CHART (real data)
function SalesBarChart({ mode, chartData }) {
  useEffect(() => {
    const ctx = document.getElementById("salesChart");
    if (!ctx || !chartData?.length) return;
    const labels   = chartData.map(m => m.month_name);
    const thisYear = chartData.map(m => m.this_year);
    const lastYear = chartData.map(m => m.last_year);
    const chart = new Chart(ctx, {
      type: mode === "line" ? "line" : "bar",
      data: {
        labels,
        datasets: [
          { label:"This Year", data:thisYear, backgroundColor:"rgba(0,152,204,0.85)", borderColor:"#0098cc", borderWidth:mode==="line"?2:0, borderRadius:4, borderSkipped:false, tension:0.4, fill:false, pointRadius:mode==="line"?3:0, barPercentage:0.75, categoryPercentage:0.72 },
          { label:"Last Year", data:lastYear, backgroundColor:"rgba(148,163,184,0.65)", borderColor:"#94a3b8", borderWidth:mode==="line"?2:0, borderRadius:4, borderSkipped:false, tension:0.4, fill:false, pointRadius:mode==="line"?3:0, barPercentage:0.75, categoryPercentage:0.72 },
        ],
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins: {
          legend:{ display:true, position:"top", align:"end", labels:{ color:"#5a6a85", font:{size:11}, boxWidth:12, boxHeight:12, padding:14 } },
          tooltip:{ backgroundColor:"#fff", borderColor:"rgba(0,0,0,0.10)", borderWidth:1, titleColor:"#5a6a85", bodyColor:"#1a2332", padding:10, callbacks:{ label:(c)=>` ${c.dataset.label}: ₱${c.raw.toLocaleString()}` } },
        },
        scales: {
          x:{ grid:{display:false}, ticks:{color:"#94a3b8",font:{size:11}} },
          y:{ grid:{color:"rgba(0,0,0,0.06)"}, ticks:{color:"#94a3b8", callback:(v)=>"₱"+(v/1000).toFixed(0)+"K"} },
        },
      },
    });
    return () => chart.destroy();
  }, [mode, JSON.stringify(chartData)]);
  return <canvas id="salesChart" height="180" />;
}

// ─── NAV SECTIONS
const navSections = [
  { label:"Overview", items:[{ icon:<GridIcon />, label:"Dashboard", active:true }] },
  { label:"HRIS",     items:[{ icon:<UsersIcon />, label:"Employees" },{ icon:<ClockIcon />, label:"Attendance" },{ icon:<FileIcon />, label:"Leave Requests" },{ icon:<DollarIcon />, label:"Payroll" }] },
  { label:"IMS",      items:[{ icon:<BoxIcon />, label:"Products" },{ icon:<ArrowsIcon />, label:"Stock Movements" },{ icon:<AlertIcon />, label:"Inventory Alerts" }] },
  { label:"SCM",      items:[{ icon:<HomeIcon />, label:"Suppliers" },{ icon:<TruckIcon />, label:"Deliveries" },{ icon:<CheckIcon />, label:"Delivery Proofs" }] },
  { label:"CRM",      items:[{ icon:<UserIcon />, label:"Customers" },{ icon:<CartIcon />, label:"Orders" },{ icon:<DollarIcon />, label:"Payments" }] },
  { label:"System",   items:[{ icon:<RolesIcon />, label:"Users & Roles" },{ icon:<SettingsIcon />, label:"Settings" },{ icon:<LogsIcon />, label:"Audit Logs" }] },
];

// ─── MAIN COMPONENT 
export default function Dashboard() {
  const [chartMode, setChartMode] = useState("bar");
  const [biData,    setBiData]    = useState(null);
  const [loading,   setLoading]   = useState(true);
  const [aiSummary, setAiSummary] = useState("");
  const [aiLoading, setAiLoading] = useState(false);
  const [notifications, setNotifications] = useState([]);
  const today = new Date().toLocaleDateString("en-PH", { month:"long", day:"numeric", year:"numeric" });

  // ── Fetch BI Summary 
  const fetchBI = useCallback(async () => {
    setLoading(true);
    try {
      const res  = await fetch("/api/bi/summary", {
        headers: { Accept:"application/json", Authorization:`Bearer ${token()}` },
      });
      const data = await res.json();
      setBiData(data);
      console.log("BI Data set:", data);
    } catch (e) {
      console.error("BI fetch error:", e);
    } finally {
      setLoading(false);
    }
  }, []);

  // ── Fetch Gemini AI Summary 
  const fetchAI = useCallback(async () => {
  setAiLoading(true);
  try {
    const res = await fetch("/api/bi/ai-summary", {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token()}`,
      },
    });
    const data = await res.json();
    setAiSummary(data.summary || "No summary available.");
  } catch (e) {
    setAiSummary("AI summary unavailable at this time.");
  } finally {
    setAiLoading(false);
  }
}, []);

  // ── Initial load 
  useEffect(() => {
    fetchBI();
  }, []);

  useEffect(() => {
  if (biData) fetchAI();
}, [biData])

  // ── Pusher real-time notifications
  useEffect(() => {
    echo.channel('attendance').listen('.attendance.recorded', (e) => {
      setNotifications(prev => [{ bg:"bg-amber-100", color:"text-amber-600", icon:<ClockIcon />, title:"Attendance Alert", body:e.data?.message||"Attendance recorded.", time:"Just now", unread:true }, ...prev.slice(0,9)]);
      fetchBI();
    });
    echo.channel('inventory').listen('.low.stock.alert', (e) => {
      setNotifications(prev => [{ bg:"bg-red-100", color:"text-red-500", icon:<AlertIcon />, title:"Inventory Alert", body:e.data?.message||"Low stock detected.", time:"Just now", unread:true }, ...prev.slice(0,9)]);
      fetchBI();
    });
    echo.channel('payments').listen('.payment.overdue', (e) => {
      setNotifications(prev => [{ bg:"bg-red-100", color:"text-red-500", icon:<DollarIcon />, title:"Overdue Payment", body:e.data?.message||"A payment is overdue.", time:"Just now", unread:true }, ...prev.slice(0,9)]);
      fetchBI();
    });
    echo.channel('deliveries').listen('.delivery.status.updated', (e) => {
      setNotifications(prev => [{ bg:"bg-green-100", color:"text-green-600", icon:<TruckIcon />, title:"Delivery Update", body:e.data?.message||"Delivery status updated.", time:"Just now", unread:true }, ...prev.slice(0,9)]);
      fetchBI();
    });
    return () => {
      echo.leaveChannel('attendance');
      echo.leaveChannel('inventory');
      echo.leaveChannel('payments');
      echo.leaveChannel('deliveries');
    };
  }, []);

  // ── Derived values from real data
  const sales     = biData?.sales     || {};
  const deliveries= biData?.deliveries|| {};
  const inventory = biData?.inventory || {};
  const attendance= biData?.attendance|| {};
  const overdue   = biData?.overdue   || {};

  const salesMomUp  = (sales.mom_change_pct ?? 0) >= 0;
  const delMomUp    = (deliveries.mom_change_pct ?? 0) >= 0;
  const invTotal    = (inventory.low_stock || 0) + (inventory.out_of_stock || 0);
  const invDelTotal = (inventory.total || 0) > 0
    ? Math.round(((inventory.in_stock || 0) / inventory.total) * 100) : 0;

  const statCards = [
    {
      label:"Total Sales (This Month)",
      value: fmt(sales.this_month_total),
      delta: loading ? "—" : `${Math.abs(sales.mom_change_pct ?? 0)}% vs last month`,
      up: salesMomUp,
      icon:<DollarIcon />, iconCls:"bg-blue-100 text-blue-600",
      chartId:"chart-sales",
      chartData:(sales.monthly_chart||[]).map(m=>m.this_year),
      color:"#3b82f6", context: sales.current_month || "This Month",
    },
    {
      label:"Total Deliveries",
      value: loading ? "—" : String(deliveries.total || 0),
      delta: loading ? "—" : `${Math.abs(deliveries.mom_change_pct ?? 0)}% vs last month`,
      up: delMomUp,
      icon:<TruckIcon />, iconCls:"bg-green-100 text-green-600",
      chartId:"chart-del",
      chartData: Array(12).fill(deliveries.this_month_total || 0),
      color:"#22c55e", context:`${deliveries.delivery_rate_pct || 0}% delivered`,
    },
    {
      label:"Inventory Alerts",
      value: loading ? "—" : String(invTotal),
      delta: `${inventory.out_of_stock || 0} out of stock`,
      up: false,
      icon:<AlertIcon />, iconCls:"bg-amber-100 text-amber-600",
      chartId:"chart-inv",
      chartData: Array(12).fill(invTotal),
      color:"#f59e0b", context:`${inventory.low_stock || 0} low stock items`,
    },
    {
      label:"Attendance Today",
      value: loading ? "—" : `${attendance.total_in || 0}/${attendance.total_employees || 0}`,
      delta: `${attendance.attendance_rate || 0}% attendance rate`,
      up: (attendance.attendance_rate || 0) >= 80,
      icon:<UsersIcon />, iconCls:"bg-cyan-100 text-cyan-600",
      chartId:"chart-att",
      chartData:(attendance.weekly_trend||[]).map(d=>d.count),
      color:"#00c2ff", context:"Present today",
    },
    {
      label:"Overdue Receivables",
      value: loading ? "—" : fmt(overdue.total_overdue_amount),
      delta: `${overdue.overdue_count || 0} Customers`,
      up: null,
      icon:<InfoIcon />, iconCls:"bg-red-100 text-red-500",
      chartId:"chart-over",
      chartData: Array(12).fill(overdue.total_overdue_amount || 0),
      color:"#f43f5e", valueCls:"text-red-500", context:"Action needed", isAlert:true,
    },
  ];

  // ── Inventory donut data
  const invDonutData = [inventory.in_stock||0, inventory.low_stock||0, inventory.out_of_stock||0];
  const invDonutTotal = invDonutData.reduce((a,b)=>a+b,0) || 1;

  // ── Delivery donut data
  const delDonutData = [deliveries.delivered||0, deliveries.in_transit||0, deliveries.pending||0, deliveries.cancelled||0];
  const delTotal     = deliveries.total || 1;

  return (
    <div className="flex font-sans text-[13px] leading-relaxed bg-[#0a0d12] text-slate-800 min-h-screen">

      {/* ── SIDEBAR ── */}
      <aside className="w-[220px] min-h-screen bg-white border-r border-black/[0.09] flex flex-col flex-shrink-0 sticky top-0 h-screen overflow-y-auto">
        <div className="px-[18px] pt-5 pb-4 border-b border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[34px] h-[34px] bg-cyan-500/10 border border-cyan-500/20 rounded-md flex items-center justify-center text-cyan-500"><Bolt /></div>
          <div>
            <div className="text-[15px] font-semibold text-slate-800 tracking-wide">Bella Erin</div>
            <div className="text-[10px] text-slate-400 tracking-widest uppercase">AT-ICE ERP</div>
          </div>
        </div>
        <nav className="py-2.5 flex-1">
          {navSections.map((sec) => (
            <div key={sec.label}>
              <div className="px-[18px] pt-3.5 pb-1 text-[10px] tracking-[0.1em] uppercase text-slate-400 font-medium">{sec.label}</div>
              {sec.items.map((item) => (
                <div key={item.label} className={`flex items-center gap-2.5 px-[18px] py-[7px] text-[13px] cursor-pointer transition-all duration-100 border-l-2 ${item.active ? "text-cyan-500 bg-cyan-500/[0.08] border-cyan-500" : "text-slate-500 border-transparent hover:text-slate-800 hover:bg-slate-50"}`}>
                  <span className="opacity-80">{item.icon}</span>{item.label}
                </div>
              ))}
            </div>
          ))}
        </nav>
        <div className="px-[18px] py-3.5 border-t border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">AD</div>
          <div>
            <div className="text-xs font-medium text-slate-800">Admin</div>
            <div className="text-[10px] text-slate-400">System Administrator</div>
          </div>
          <div className="w-[7px] h-[7px] rounded-full bg-green-500 ml-auto shadow-[0_0_5px_rgba(22,163,74,0.4)]" />
        </div>
      </aside>

      {/* ── MAIN ── */}
      <div className="flex-1 min-w-0 flex flex-col overflow-hidden">

        {/* ── TOPBAR ── */}
        <header className="h-14 bg-white border-b border-black/[0.09] flex items-center px-6 gap-4 flex-shrink-0 sticky top-0 z-10">
          <div>
            <div className="text-base font-semibold text-slate-800">Dashboard</div>
            <div className="text-xs text-slate-400 flex items-center gap-1">Home <ChevRight /> <span className="text-slate-600">Dashboard</span></div>
          </div>
          <div className="flex-1" />
          <div className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500">
            <CalIcon /> {today}
          </div>
          <button onClick={fetchBI} className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500 transition-colors">
            <RefreshIcon /> Refresh
          </button>
          <div className="flex items-center gap-2.5 px-2.5 py-1.5 pl-1.5 bg-white border border-black/[0.09] rounded-md cursor-pointer hover:bg-slate-50 transition-colors">
            <div className="w-[30px] h-[30px] rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 border border-cyan-500/20 flex items-center justify-center text-[11px] font-semibold text-cyan-500">AD</div>
            <div>
              <div className="text-xs font-medium text-slate-800">Admin</div>
              <div className="text-[10px] text-slate-400">System Administrator</div>
            </div>
            <span className="ml-1 text-slate-400"><ChevDown /></span>
          </div>
        </header>

        {/* ── CONTENT ── */}
        <main className="flex-1 overflow-y-auto p-6 pb-8 flex flex-col gap-4">

          {/* STAT CARDS */}
          <div className="grid grid-cols-5 gap-3">
            {statCards.map((card) => (
              <div key={card.label} className={`bg-white border rounded-xl p-4 flex flex-col gap-2.5 cursor-pointer hover:shadow-md hover:-translate-y-px transition-all duration-150 ${card.isAlert ? "border-red-300 bg-red-50/60 hover:border-red-400" : "border-black/[0.09] hover:border-black/20"}`}>
                <div className="flex items-start justify-between">
                  <span className={`text-[11px] font-medium tracking-[0.02em] ${card.isAlert ? "text-red-400" : "text-slate-400"}`}>{card.label}</span>
                  <div className={`w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0 ${card.iconCls}`}>{card.icon}</div>
                </div>
                <div className={`text-[22px] font-semibold font-mono tracking-tight leading-none ${card.valueCls || "text-slate-800"}`}>{card.value}</div>
                {card.up !== null ? (
                  <div className={`flex items-center gap-1 text-[11px] font-medium ${card.up ? "text-green-600" : "text-red-500"}`}>
                    {card.up ? <ArrowUp /> : <ArrowDown />}{card.delta}
                  </div>
                ) : (
                  <div className="text-[11px] text-red-400 font-medium">{card.delta} — {card.context}</div>
                )}
                {card.up !== null && <div className="text-[10px] text-slate-400">{card.context}</div>}
                {card.chartData?.length > 0 && <MiniChart id={card.chartId} data={card.chartData} color={card.color} />}
              </div>
            ))}
          </div>

          {/* SALES + RIGHT COLUMN */}
          <div className="grid grid-cols-[1fr_340px] gap-3">

            {/* LEFT */}
            <div className="flex flex-col gap-3">

              {/* Sales Chart */}
              <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
                <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
                  <div>
                    <div className="text-[13px] font-semibold text-slate-800">Sales Overview</div>
                    <div className="text-[11px] text-slate-400 mt-0.5">{sales.current_month || "This Month"}</div>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="flex items-center bg-slate-100 rounded-md p-0.5 gap-0.5">
                      {["bar","line"].map((m) => (
                        <button key={m} onClick={() => setChartMode(m)} className={`px-2.5 py-1 text-[10px] font-medium rounded transition-colors border-0 cursor-pointer capitalize ${chartMode === m ? "bg-white text-slate-800 shadow-sm" : "text-slate-400 bg-transparent hover:text-slate-600"}`}>{m}</button>
                      ))}
                    </div>
                    <div className="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-50 border border-black/[0.09] rounded-md text-[11px] text-slate-500">
                      This Year vs Last Year
                    </div>
                  </div>
                </div>
                <div className="px-[18px] pt-4 pb-0">
                  <SalesBarChart mode={chartMode} chartData={sales.monthly_chart || []} />
                </div>
                <div className="grid grid-cols-5 border-t border-black/[0.09]">
                  {[
                    ["Total Sales",      fmt(sales.this_month_total),  false],
                    ["Total Orders",     String(sales.total_orders||0), false],
                    ["Avg Order Value",  fmt(sales.avg_order_value),   false],
                    ["Paid",             fmt(sales.paid_this_month),   false],
                    ["Unpaid",           fmt(sales.unpaid_this_month), true ],
                  ].map(([label,val,danger],i) => (
                    <div key={label} className={`px-[18px] py-3 ${i < 4 ? "border-r border-black/[0.09]" : ""}`}>
                      <div className="text-[10px] text-slate-400 mb-0.5">{label}</div>
                      <div className={`text-[13px] font-semibold font-mono ${danger ? "text-red-500" : "text-slate-800"}`}>{val}</div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Inventory + Delivery */}
              <div className="grid grid-cols-2 gap-3">

                {/* Inventory Summary */}
                <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
                  <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
                    <div className="text-[13px] font-semibold text-slate-800">Inventory Summary</div>
                    <a className="text-[11px] text-cyan-500 cursor-pointer hover:text-cyan-400 transition-colors">View all →</a>
                  </div>
                  <div className="flex items-start gap-6 p-[18px]">
                    <div className="w-[90px] h-[90px] flex-shrink-0">
                      <DonutChart id="invDonut" data={invDonutData} colors={["#16a34a","#d97706","#e11d48"]} />
                    </div>
                    <div className="flex-1 flex flex-col gap-2.5">
                      {[
                        ["#16a34a","In Stock",     `${Math.round((inventory.in_stock||0)/invDonutTotal*100)}%`,    inventory.in_stock||0],
                        ["#d97706","Low Stock",    `${Math.round((inventory.low_stock||0)/invDonutTotal*100)}%`,   inventory.low_stock||0],
                        ["#e11d48","Out of Stock", `${Math.round((inventory.out_of_stock||0)/invDonutTotal*100)}%`,inventory.out_of_stock||0],
                      ].map(([color,label,p,val]) => (
                        <div key={label} className="flex items-center gap-2">
                          <div className="w-2 h-2 rounded-full flex-shrink-0" style={{background:color}} />
                          <div><div className="text-xs text-slate-500">{label}</div><div className="text-[10px] text-slate-400">{p}</div></div>
                          <div className="text-xs font-semibold text-slate-800 font-mono ml-auto">{val}</div>
                        </div>
                      ))}
                      <div className="mt-1.5 pt-2.5 border-t border-black/[0.09] w-full">
                        <div className="text-[10px] text-slate-400">Total Products</div>
                        <div className="text-xl font-semibold text-slate-800 font-mono">{inventory.total||0}</div>
                      </div>
                    </div>
                  </div>
                  <div className="border-t border-black/[0.09] px-[18px] pb-3 pt-2.5">
                    <div className="text-[10px] tracking-widest uppercase text-slate-400 mb-2">Critical Stock Items</div>
                    {(inventory.critical_items||[]).length === 0 && (
                      <div className="text-[11px] text-slate-400 py-1">No critical items 🎉</div>
                    )}
                    {(inventory.critical_items||[]).map((item, i, arr) => (
                      <div key={item.id} className={`flex justify-between items-center py-1.5 text-xs ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>
                        <span className={item.current_stock === 0 ? "text-red-500 font-medium" : "text-slate-800"}>{item.name}</span>
                        <span className={`text-[10px] px-2 py-0.5 rounded-full font-semibold ${item.current_stock === 0 ? "bg-red-100 text-red-500" : "bg-amber-100 text-amber-600"}`}>
                          {item.current_stock === 0 ? "0 left" : `${item.current_stock} left`}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Delivery Status */}
                <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
                  <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
                    <div className="text-[13px] font-semibold text-slate-800">Delivery Status</div>
                    <a className="text-[11px] text-cyan-500 cursor-pointer hover:text-cyan-400 transition-colors">View all →</a>
                  </div>
                  <div className="flex items-start gap-6 p-[18px]">
                    <div className="w-[90px] h-[90px] flex-shrink-0">
                      <DonutChart id="delDonut" data={delDonutData} colors={["#2563eb","#16a34a","#d97706","#e11d48"]} />
                    </div>
                    <div className="flex-1 flex flex-col gap-2.5">
                      {[
                        ["#2563eb","Delivered",  `${Math.round((deliveries.delivered||0)/delTotal*100)}%`,  deliveries.delivered||0,  false],
                        ["#16a34a","In Transit", `${Math.round((deliveries.in_transit||0)/delTotal*100)}%`, deliveries.in_transit||0, false],
                        ["#d97706","Pending",    `${Math.round((deliveries.pending||0)/delTotal*100)}%`,    deliveries.pending||0,    true ],
                        ["#e11d48","Cancelled",  `${Math.round((deliveries.cancelled||0)/delTotal*100)}%`,  deliveries.cancelled||0,  false],
                      ].map(([color,label,p,val,highlight]) => (
                        <div key={label} className={`flex items-center gap-2 ${highlight ? "bg-amber-50 -mx-2 px-2 rounded-md py-0.5" : ""}`}>
                          <div className="w-2 h-2 rounded-full flex-shrink-0" style={{background:color}} />
                          <div>
                            <div className={`text-xs ${highlight ? "text-amber-700 font-medium" : "text-slate-500"}`}>{label} {highlight && "⚠"}</div>
                            <div className="text-[10px] text-slate-400">{p}</div>
                          </div>
                          <div className={`text-xs font-semibold font-mono ml-auto ${highlight ? "text-amber-600" : "text-slate-800"}`}>{val}</div>
                        </div>
                      ))}
                      <div className="mt-1.5 pt-2.5 border-t border-black/[0.09] w-full">
                        <div className="text-[10px] text-slate-400">Total Deliveries</div>
                        <div className="text-xl font-semibold text-slate-800 font-mono">{deliveries.total||0}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* RIGHT COLUMN */}
            <div className="flex flex-col gap-3">

              {/* AI Summary */}
              <div className="bg-white border border-cyan-200 rounded-xl overflow-hidden">
                <div className="px-[18px] py-3.5 border-b border-cyan-100 flex items-center justify-between bg-cyan-500/[0.04]">
                  <div className="text-[13px] font-semibold text-slate-800">AI Summary</div>
                  <span className="text-[10px] px-2 py-0.5 bg-cyan-500/10 text-cyan-500 rounded-full font-mono">Gemini</span>
                </div>
                <div className="p-[18px]">
                  {aiLoading ? (
                    <div className="flex items-center gap-2 text-xs text-slate-400">
                      <span className="w-3 h-3 border-2 border-cyan-400/40 border-t-cyan-500 rounded-full animate-spin" />
                      Generating AI summary…
                    </div>
                  ) : (
                    <p className="text-xs text-slate-500 leading-relaxed">{aiSummary || "No summary available."}</p>
                  )}
                  <div className="border-t border-black/[0.09] pt-2.5 mt-2.5 flex flex-col gap-1.5">
                    {[
                      { dot:"bg-green-500", text:<>Delivery rate: <span className="text-slate-800 font-semibold">{deliveries.delivery_rate_pct||0}%</span> completed</> },
                      { dot:(deliveries.pending||0)>0?"bg-amber-500":"bg-green-500", text:<><span className="text-slate-800 font-semibold">{deliveries.pending||0} pending deliveries</span> today</> },
                      { dot:(inventory.out_of_stock||0)>0?"bg-red-500":"bg-green-500", text:(inventory.out_of_stock||0)>0 ? <><span className="text-red-500 font-semibold">{inventory.out_of_stock} items out of stock</span> — reorder needed</> : <><span className="text-green-600 font-semibold">All items in stock</span> ✓</> },
                    ].map((item,i) => (
                      <div key={i} className="flex items-center gap-2 text-[11px]">
                        <span className={`w-1.5 h-1.5 rounded-full flex-shrink-0 ${item.dot}`} />
                        <span className="text-slate-500">{item.text}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              {/* Notifications */}
              <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
                <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
                  <div className="text-[13px] font-semibold text-slate-800">Recent Notifications</div>
                  <a className="text-[11px] text-cyan-500 cursor-pointer hover:text-cyan-400 transition-colors">View all</a>
                </div>
                {notifications.length === 0 && (
                  <div className="px-[18px] py-4 text-[11px] text-slate-400">No notifications yet — real-time updates will appear here.</div>
                )}
                {notifications.map((n,i,arr) => (
                  <div key={i} className={`flex items-start gap-2.5 px-[18px] py-3 cursor-pointer hover:bg-slate-50 transition-colors relative ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>
                    <div className={`w-[30px] h-[30px] rounded-md flex items-center justify-center flex-shrink-0 mt-0.5 ${n.bg} ${n.color}`}>{n.icon}</div>
                    <div>
                      <div className="text-xs font-medium text-slate-800">{n.title}</div>
                      <div className="text-[11px] text-slate-500 mt-0.5 leading-snug">{n.body}</div>
                      <div className="text-[10px] text-slate-400 mt-0.5">{n.time}</div>
                    </div>
                    {n.unread && <div className="absolute right-[18px] top-1/2 -translate-y-1/2 w-1.5 h-1.5 rounded-full bg-cyan-500" />}
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* OVERDUE RECEIVABLES */}
          <div className="grid grid-cols-[1fr_340px] gap-3">

            {/* Attendance Summary */}
            <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
              <div className="px-[18px] py-3.5 border-b border-black/[0.09]">
                <div className="text-[13px] font-semibold text-slate-800">Attendance Today</div>
                <div className="text-[11px] text-slate-400 mt-0.5">{attendance.date || "Today"}</div>
              </div>
              <div className="grid grid-cols-5 divide-x divide-black/[0.06]">
                {[
                  ["Present",  attendance.present||0,  "text-green-600"],
                  ["Late",     attendance.late||0,     "text-amber-500"],
                  ["Absent",   attendance.absent||0,   "text-red-500"],
                  ["Half Day", attendance.half_day||0, "text-blue-500"],
                  ["On Leave", attendance.on_leave||0, "text-purple-500"],
                ].map(([label,val,cls]) => (
                  <div key={label} className="px-[18px] py-3 text-center">
                    <div className={`text-[22px] font-semibold font-mono ${cls}`}>{val}</div>
                    <div className="text-[10px] text-slate-400 mt-0.5">{label}</div>
                  </div>
                ))}
              </div>
              <div className="px-[18px] py-2.5 border-t border-black/[0.06] flex items-center justify-between">
                <span className="text-[11px] text-slate-400">Attendance Rate</span>
                <span className="text-[13px] font-semibold text-slate-800 font-mono">{attendance.attendance_rate||0}% ({attendance.total_in||0} / {attendance.total_employees||0})</span>
              </div>
            </div>

            {/* Overdue Receivables */}
            <div className="bg-white border border-red-200 rounded-xl overflow-hidden">
              <div className="px-[18px] py-3.5 border-b border-red-100 bg-red-50/40 flex items-center justify-between">
                <div>
                  <div className="text-[13px] font-semibold text-slate-800">Overdue Receivables</div>
                  <div className="text-[10px] text-red-400 mt-0.5">Action required</div>
                </div>
                <a className="text-[11px] text-cyan-500 cursor-pointer hover:text-cyan-400 transition-colors">View all</a>
              </div>
              {(overdue.receipts||[]).length === 0 && (
                <div className="px-[18px] py-4 text-[11px] text-slate-400">No overdue receivables 🎉</div>
              )}
              {(overdue.receipts||[]).map((r) => (
                <div key={r.id} className="grid grid-cols-[1fr_auto_auto] gap-4 items-center px-[18px] py-2.5 border-b border-black/[0.09] last:border-b-0 hover:bg-slate-50 transition-colors cursor-pointer">
                  <div className="text-xs text-slate-800 font-medium">{r.customer_name}</div>
                  <div className="text-xs text-slate-800 font-mono font-semibold">{fmt(r.total_amount)}</div>
                  <div className={`text-[11px] px-1.5 py-0.5 rounded-full font-medium ${r.days_overdue > 15 ? "bg-red-100 text-red-500" : "bg-amber-100 text-amber-600"}`}>{r.days_overdue} days</div>
                </div>
              ))}
              <div className="flex items-center justify-between px-[18px] py-3 border-t border-red-100 bg-red-50/40">
                <span className="text-[11px] text-slate-400 font-medium">Total Overdue</span>
                <span className="text-sm font-bold text-red-500 font-mono">{fmt(overdue.total_overdue_amount)}</span>
              </div>
            </div>
          </div>

          {/* SYSTEM STATUS */}
          <div className="grid grid-cols-5 gap-3">
            {[
              { icon:<UsersIcon />, bg:"bg-blue-100",  color:"text-blue-600",  label:"Active Users",       value:"Online",      sub:"Session active",          dot:true  },
              { icon:<CalIcon />,   bg:"bg-green-100", color:"text-green-600", label:"Today's Attendance", value:`${attendance.total_in||0} / ${attendance.total_employees||0}`, sub:`${attendance.attendance_rate||0}%`, dot:false },
              { icon:<PulseIcon />, bg:"bg-green-100", color:"text-green-600", label:"Server Status",      value:"Normal",      sub:"All systems operational", dot:true  },
              { icon:<WifiIcon />,  bg:"bg-cyan-100",  color:"text-cyan-500",  label:"Pusher Status",      value:"Connected",   sub:"Real-time active",        dot:true  },
              { icon:<DbIcon />,    bg:"bg-blue-100",  color:"text-blue-600",  label:"Database",           value:"MySQL 8.0",   sub:"Connected",               dot:true  },
            ].map((s) => (
              <div key={s.label} className="bg-white border border-black/[0.09] rounded-xl px-4 py-3.5 flex items-center gap-3 cursor-pointer hover:border-black/20 hover:-translate-y-px transition-all duration-150">
                <div className={`w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0 ${s.bg} ${s.color}`}>{s.icon}</div>
                <div>
                  <div className="text-[10px] text-slate-400 uppercase tracking-[0.07em]">{s.label}</div>
                  <div className="text-[13px] font-semibold text-slate-800 mt-0.5">{s.value}</div>
                  <div className="text-[10px] text-slate-400 mt-0.5 flex items-center gap-1">
                    {s.dot && <span className="w-1.5 h-1.5 rounded-full bg-green-500 inline-block" />}
                    {s.sub}
                  </div>
                </div>
              </div>
            ))}
          </div>

        </main>
      </div>
    </div>
  );
}