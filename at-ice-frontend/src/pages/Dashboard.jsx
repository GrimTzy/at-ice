import { useEffect, useState } from "react";
import { Chart } from "chart.js/auto";
import echo from "../echo";

// ICONS 
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
const BellIcon   = () => <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>;
const MsgIcon    = () => <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>;
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
const PlusIcon   = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>;
const TrendUpIcon   = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>;
const TrendDownIcon = () => <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>;

// MINI LINE CHART 
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
  }, []);
  return <div className="h-9 -mx-1 -mb-1 mt-1"><canvas id={id} /></div>;
}

// DONUT CHART 
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
  }, []);
  return <canvas id={id} className="w-[90px] h-[90px]" />;
}

// SALES BAR CHART (3-dataset with toggle)
function SalesBarChart({ mode }) {
  useEffect(() => {
    const ctx = document.getElementById("salesChart");
    if (!ctx) return;
    const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    const chart = new Chart(ctx, {
      type: mode === "line" ? "line" : "bar",
      data: {
        labels: months,
        datasets: [
          { label:"This Year",  data:[980000,1050000,1120000,1256780,890000,1340000,1280000,1190000,1350000,1420000,1380000,1560000], backgroundColor:"rgba(0,152,204,0.85)", borderColor:"#0098cc", borderWidth: mode==="line"?2:0, borderRadius:4, borderSkipped:false, tension:0.4, fill:false, pointRadius: mode==="line"?3:0, barPercentage:0.75, categoryPercentage:0.72 },
          { label:"Last Month", data:[820000,870000,960000,1050000,760000,1100000,1080000,990000,1130000,1200000,1160000,1320000], backgroundColor:"rgba(99,102,241,0.75)", borderColor:"#6366f1", borderWidth: mode==="line"?2:0, borderRadius:4, borderSkipped:false, tension:0.4, fill:false, pointRadius: mode==="line"?3:0, barPercentage:0.75, categoryPercentage:0.72 },
          { label:"Last Year",  data:[640000,700000,750000,820000,590000,870000,840000,780000,890000,940000,910000,1020000], backgroundColor:"rgba(148,163,184,0.65)", borderColor:"#94a3b8", borderWidth: mode==="line"?2:0, borderRadius:4, borderSkipped:false, tension:0.4, fill:false, pointRadius: mode==="line"?3:0, barPercentage:0.75, categoryPercentage:0.72 },
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
  }, [mode]);
  return <canvas id="salesChart" height="180" />;
}

// ─── NAV SECTIONS ─────────────────────────────────────────────────────────────
const navSections = [
  { label:"Overview", items:[{ icon:<GridIcon />, label:"Dashboard", active:true }] },
  { label:"HRIS",     items:[{ icon:<UsersIcon />, label:"Employees" },{ icon:<ClockIcon />, label:"Attendance" },{ icon:<FileIcon />, label:"Leave Requests" },{ icon:<DollarIcon />, label:"Payroll" }] },
  { label:"IMS",      items:[{ icon:<BoxIcon />, label:"Products" },{ icon:<ArrowsIcon />, label:"Stock Movements" },{ icon:<AlertIcon />, label:"Inventory Alerts" }] },
  { label:"SCM",      items:[{ icon:<HomeIcon />, label:"Suppliers" },{ icon:<TruckIcon />, label:"Deliveries" },{ icon:<CheckIcon />, label:"Delivery Proofs" }] },
  { label:"CRM",      items:[{ icon:<UserIcon />, label:"Customers" },{ icon:<CartIcon />, label:"Orders" },{ icon:<DollarIcon />, label:"Payments" }] },
  { label:"System",   items:[{ icon:<RolesIcon />, label:"Users & Roles" },{ icon:<SettingsIcon />, label:"Settings" },{ icon:<LogsIcon />, label:"Audit Logs" }] },
];

// ─── STAT CARDS ───────────────────────────────────────────────────────────────
const statCards = [
  { label:"Total Sales (This Month)", value:"₱1,256,780", delta:"18.6% vs last month", up:true,  icon:<DollarIcon />, iconCls:"bg-blue-100 text-blue-600",  chartId:"chart-sales", chartData:[85,92,78,95,88,102,115,108,125,118,132,145], color:"#3b82f6", context:"This Month" },
  { label:"Total Deliveries",         value:"128",         delta:"12.5% vs last month", up:true,  icon:<TruckIcon />,  iconCls:"bg-green-100 text-green-600", chartId:"chart-del",   chartData:[60,72,65,80,75,88,92,85,95,90,102,110],    color:"#22c55e", context:"60.9% delivered" },
  { label:"Inventory Alerts",         value:"14",          delta:"7 vs last month",     up:false, icon:<AlertIcon />,  iconCls:"bg-amber-100 text-amber-600", chartId:"chart-inv",   chartData:[20,18,22,16,25,19,21,24,18,22,16,14],      color:"#f59e0b", context:"1 critical item" },
  { label:"Pending Orders",           value:"23",          delta:"4 vs last month",     up:true,  icon:<FileIcon />,   iconCls:"bg-cyan-100 text-cyan-600",   chartId:"chart-pend",  chartData:[15,18,20,17,22,19,21,24,20,23,21,23],      color:"#00c2ff", context:"Need attention today" },
  { label:"Overdue Receivables",      value:"₱189,450",    delta:"3 Customers",         up:null,  icon:<InfoIcon />,   iconCls:"bg-red-100 text-red-500",     chartId:"chart-over",  chartData:[120,135,142,130,155,148,162,170,158,175,180,189], color:"#f43f5e", valueCls:"text-red-500", context:"Action needed", isAlert:true },
];

// ─── TOP PRODUCTS DATA (with trend) ──────────────────────────────────────────
const topProducts = [
  { name:"TI-10KG (Tube Ice 10kg Bag)", sold:"1,256", revenue:"₱439,600", trend:8,  up:true  },
  { name:"TI-5KG (Tube Ice 5kg Bag)",   sold:"2,034", revenue:"₱356,000", trend:3,  up:true  },
  { name:"TI-20KG (Tube Ice 20kg Bag)", sold:"876",   revenue:"₱280,320", trend:2,  up:false },
  { name:"TI-3KG (Tube Ice 3kg Bag)",   sold:"1,145", revenue:"₱180,860", trend:11, up:true  },
  { name:"TI-FLAKES (Ice Flakes)",       sold:"650",   revenue:"₱75,000",  trend:5,  up:false },
];

// ─── MAIN COMPONENT ───────────────────────────────────────────────────────────
export default function Dashboard() {
  const [chartMode, setChartMode] = useState("bar");
  const [notifications, setNotifications] = useState([
    { bg:"bg-amber-100", color:"text-amber-600", icon:<ClockIcon />,  title:"Attendance Alert",   body:"Juan Dela Cruz time in recorded at 08:02 AM",           time:"2 minutes ago",  unread:true },
    { bg:"bg-green-100", color:"text-green-600", icon:<CheckIcon />,  title:"Delivery Completed", body:"Delivery ORD-2026-412 delivered to SM Fairview Branch",  time:"15 minutes ago", unread:true },
    { bg:"bg-red-100",   color:"text-red-500",   icon:<AlertIcon />,  title:"Inventory Alert",    body:"TI-10KG stock is below minimum level (35 left)",         time:"25 minutes ago", unread:true },
    { bg:"bg-red-100",   color:"text-red-500",   icon:<DollarIcon />, title:"Overdue Payment",    body:"Pickup Coffee Inc. is 18 days overdue (₱75,450.00)",     time:"1 hour ago",     unread:false },
  ]);

  useEffect(() => {
    // Attendance notifications
    echo.channel('attendance').listen('.attendance.recorded', (e) => {
      setNotifications(prev => [{
        bg:"bg-amber-100", color:"text-amber-600", icon:<ClockIcon />,
        title:"Attendance Alert",
        body: e.data?.message || "Attendance recorded.",
        time:"Just now",
        unread:true,
      }, ...prev.slice(0, 9)]);
    });

    // Inventory low stock
    echo.channel('inventory').listen('.low.stock.alert', (e) => {
      setNotifications(prev => [{
        bg:"bg-red-100", color:"text-red-500", icon:<AlertIcon />,
        title:"Inventory Alert",
        body: e.data?.message || "Low stock detected.",
        time:"Just now",
        unread:true,
      }, ...prev.slice(0, 9)]);
    });

    // Overdue payments
    echo.channel('payments').listen('.payment.overdue', (e) => {
      setNotifications(prev => [{
        bg:"bg-red-100", color:"text-red-500", icon:<DollarIcon />,
        title:"Overdue Payment",
        body: e.data?.message || "A payment is overdue.",
        time:"Just now",
        unread:true,
      }, ...prev.slice(0, 9)]);
    });

    // Delivery status updates
    echo.channel('deliveries').listen('.delivery.status.updated', (e) => {
      setNotifications(prev => [{
        bg:"bg-green-100", color:"text-green-600", icon:<TruckIcon />,
        title:"Delivery Update",
        body: e.data?.message || "Delivery status updated.",
        time:"Just now",
        unread:true,
      }, ...prev.slice(0, 9)]);
    });

    return () => {
      echo.leaveChannel('attendance');
      echo.leaveChannel('inventory');
      echo.leaveChannel('payments');
      echo.leaveChannel('deliveries');
    };
  }, []);

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
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">KA</div>
          <div>
            <div className="text-xs font-medium text-slate-800">Valencia, Rhalf</div>
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
          <div className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-black/20 transition-colors">
            <CalIcon /> April 28, 2026 <ChevDown />
          </div>

          <div className="flex items-center gap-2.5 px-2.5 py-1.5 pl-1.5 bg-white border border-black/[0.09] rounded-md cursor-pointer hover:bg-slate-50 transition-colors">
            <div className="w-[30px] h-[30px] rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 border border-cyan-500/20 flex items-center justify-center text-[11px] font-semibold text-cyan-500">KA</div>
            <div>
              <div className="text-xs font-medium text-slate-800">Valencia, Rhalf</div>
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
                <MiniChart id={card.chartId} data={card.chartData} color={card.color} />
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
                    <div className="text-[11px] text-slate-400 mt-0.5">This Month</div>
                  </div>
                  <div className="flex items-center gap-2">
                    {/* Chart mode toggle */}
                    <div className="flex items-center bg-slate-100 rounded-md p-0.5 gap-0.5">
                      {["bar","line"].map((m) => (
                        <button key={m} onClick={() => setChartMode(m)} className={`px-2.5 py-1 text-[10px] font-medium rounded transition-colors border-0 cursor-pointer capitalize ${chartMode === m ? "bg-white text-slate-800 shadow-sm" : "text-slate-400 bg-transparent hover:text-slate-600"}`}>{m}</button>
                      ))}
                    </div>
                    <div className="flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-50 border border-black/[0.09] rounded-md text-[11px] text-slate-500 cursor-pointer hover:bg-slate-100 transition-colors">
                      2026 vs 2025 <ChevDown />
                    </div>
                  </div>
                </div>
                <div className="px-[18px] pt-4 pb-0">
                  <SalesBarChart mode={chartMode} />
                </div>
                <div className="grid grid-cols-5 border-t border-black/[0.09]">
                  {[["Total Sales","₱1,256,780",false],["Total Orders","342",false],["Avg Order Value","₱3,676",false],["Paid","₱1,067,330",false],["Unpaid","₱189,450",true]].map(([label,val,danger],i) => (
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
                      <DonutChart id="invDonut" data={[78,28,20]} colors={["#16a34a","#d97706","#e11d48"]} />
                    </div>
                    <div className="flex-1 flex flex-col gap-2.5">
                      {[["#16a34a","In Stock","61.9%",78],["#d97706","Low Stock","22.2%",28],["#e11d48","Out of Stock","15.9%",20]].map(([color,label,pct,val]) => (
                        <div key={label} className="flex items-center gap-2">
                          <div className="w-2 h-2 rounded-full flex-shrink-0" style={{background:color}} />
                          <div><div className="text-xs text-slate-500">{label}</div><div className="text-[10px] text-slate-400">{pct}</div></div>
                          <div className="text-xs font-semibold text-slate-800 font-mono ml-auto">{val}</div>
                        </div>
                      ))}
                      <div className="mt-1.5 pt-2.5 border-t border-black/[0.09] w-full">
                        <div className="text-[10px] text-slate-400">Total Products</div>
                        <div className="text-xl font-semibold text-slate-800 font-mono">126</div>
                      </div>
                    </div>
                  </div>
                  {/* Critical stock — out of stock pinned to top */}
                  <div className="border-t border-black/[0.09] px-[18px] pb-3 pt-2.5">
                    <div className="text-[10px] tracking-widest uppercase text-slate-400 mb-2">Critical Stock Items</div>
                    {[
                      ["TI-3KG (Tube Ice 3kg)", "0 left", "bg-red-100 text-red-500", true],
                      ["TI-10KG (Tube Ice 10kg)", "35 left", "bg-amber-100 text-amber-600", false],
                      ["TI-FLAKES (Ice Flakes)", "18 left", "bg-amber-100 text-amber-600", false],
                    ].map(([name, qty, cls, isOut], i, arr) => (
                      <div key={name} className={`flex justify-between items-center py-1.5 text-xs ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>
                        <span className={`${isOut ? "text-red-500 font-medium" : "text-slate-800"}`}>{name}</span>
                        <span className={`text-[10px] px-2 py-0.5 rounded-full font-semibold ${cls}`}>{qty}</span>
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
                      <DonutChart id="delDonut" data={[78,32,15,3]} colors={["#2563eb","#16a34a","#d97706","#e11d48"]} />
                    </div>
                    <div className="flex-1 flex flex-col gap-2.5">
                      {[["#2563eb","Delivered","60.9%",78],["#16a34a","In Transit","25.0%",32],["#d97706","Pending","11.7%",15,true],["#e11d48","Cancelled","2.4%",3]].map(([color,label,pct,val,highlight]) => (
                        <div key={label} className={`flex items-center gap-2 ${highlight ? "bg-amber-50 -mx-2 px-2 rounded-md py-0.5" : ""}`}>
                          <div className="w-2 h-2 rounded-full flex-shrink-0" style={{background:color}} />
                          <div>
                            <div className={`text-xs ${highlight ? "text-amber-700 font-medium" : "text-slate-500"}`}>{label} {highlight && "⚠"}</div>
                            <div className="text-[10px] text-slate-400">{pct}</div>
                          </div>
                          <div className={`text-xs font-semibold font-mono ml-auto ${highlight ? "text-amber-600" : "text-slate-800"}`}>{val}</div>
                        </div>
                      ))}
                      <div className="mt-1.5 pt-2.5 border-t border-black/[0.09] w-full">
                        <div className="text-[10px] text-slate-400">Total Deliveries</div>
                        <div className="text-xl font-semibold text-slate-800 font-mono">128</div>
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
                  <span className="text-[10px] px-2 py-0.5 bg-cyan-500/10 text-cyan-500 rounded-full font-mono">AI</span>
                </div>
                <div className="p-[18px] flex flex-col gap-2.5">
                  <p className="text-xs text-slate-500 leading-relaxed">
                    Sales are up <span className="text-green-600 font-semibold">18.6%</span> vs last month with ₱1.25M in total revenue. Inventory has <span className="text-red-500 font-semibold">1 critical item</span> out of stock (TI-3KG). Overdue receivables stand at <span className="text-amber-600 font-semibold">₱189,450</span> across 3 customers.
                  </p>
                  <div className="border-t border-black/[0.09] pt-2.5 flex flex-col gap-1.5">
                    {[
                      { dot:"bg-green-500", text:<>Delivery rate healthy at <span className="text-slate-800 font-semibold">60.9%</span> completed</> },
                      { dot:"bg-amber-500", text:<><span className="text-slate-800 font-semibold">23 pending orders</span> need attention today</> },
                      { dot:"bg-red-500",   text:<>TI-3KG is <span className="text-red-500 font-semibold">out of stock</span> — reorder needed</> },
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

          {/* TOP PRODUCTS + OVERDUE */}
          <div className="grid grid-cols-[1fr_340px] gap-3">

            {/* Top Products with trend */}
            <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
              <div className="px-[18px] py-3.5 border-b border-black/[0.09]">
                <div className="text-[13px] font-semibold text-slate-800">Top Selling Products</div>
                <div className="text-[11px] text-slate-400 mt-0.5">This Month</div>
              </div>
              <table className="w-full border-collapse">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-[10px] font-medium tracking-widest uppercase text-slate-400 border-b border-black/[0.09] text-left">Product</th>
                    <th className="px-3 py-2 text-[10px] font-medium tracking-widest uppercase text-slate-400 border-b border-black/[0.09] text-right">Sold</th>
                    <th className="px-3 py-2 text-[10px] font-medium tracking-widest uppercase text-slate-400 border-b border-black/[0.09] text-right">Revenue</th>
                    <th className="px-3 py-2 text-[10px] font-medium tracking-widest uppercase text-slate-400 border-b border-black/[0.09] text-right">Trend</th>
                  </tr>
                </thead>
                <tbody>
                  {topProducts.map((p,i,arr) => (
                    <tr key={p.name} className="hover:bg-slate-50 transition-colors">
                      <td className={`px-3 py-2.5 text-xs text-slate-800 ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>{p.name}</td>
                      <td className={`px-3 py-2.5 text-xs text-slate-500 font-mono text-right ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>{p.sold}</td>
                      <td className={`px-3 py-2.5 text-xs text-slate-800 font-mono font-semibold text-right ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>{p.revenue}</td>
                      <td className={`px-3 py-2.5 text-right ${i < arr.length-1 ? "border-b border-black/[0.09]" : ""}`}>
                        <span className={`inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full ${p.up ? "bg-green-50 text-green-600" : "bg-red-50 text-red-500"}`}>
                          {p.up ? <TrendUpIcon /> : <TrendDownIcon />}{p.trend}%
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
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
              {[
                ["Pickup Coffee Inc.",        "₱75,450", 18, true ],
                ["Alfamart – Branch 045",     "₱52,300", 16, true ],
                ["SM Hypermarket – Caloocan", "₱35,700", 15, false],
                ["WalterMart – North Edsa",   "₱26,000", 14, false],
              ].map(([name,amt,days,critical]) => (
                <div key={name} className="grid grid-cols-[1fr_auto_auto] gap-4 items-center px-[18px] py-2.5 border-b border-black/[0.09] last:border-b-0 hover:bg-slate-50 transition-colors cursor-pointer">
                  <div className="text-xs text-slate-800 font-medium">{name}</div>
                  <div className="text-xs text-slate-800 font-mono font-semibold">{amt}</div>
                  <div className={`text-[11px] px-1.5 py-0.5 rounded-full font-medium ${critical ? "bg-red-100 text-red-500" : "bg-amber-100 text-amber-600"}`}>{days} days</div>
                </div>
              ))}
              <div className="flex items-center justify-between px-[18px] py-3 border-t border-red-100 bg-red-50/40">
                <span className="text-[11px] text-slate-400 font-medium">Total Overdue</span>
                <span className="text-sm font-bold text-red-500 font-mono">₱189,450</span>
              </div>
            </div>
          </div>

          {/* SYSTEM STATUS */}
          <div className="grid grid-cols-5 gap-3">
            {[
              { icon:<UsersIcon />, bg:"bg-blue-100",  color:"text-blue-600",  label:"Active Users",       value:"15",        sub:"Online now",               dot:true  },
              { icon:<CalIcon />,   bg:"bg-green-100", color:"text-green-600", label:"Today's Attendance", value:"142 / 168", sub:"84.52%",                   dot:false },
              { icon:<PulseIcon />, bg:"bg-green-100", color:"text-green-600", label:"Server Status",      value:"Normal",    sub:"All systems operational",  dot:true  },
              { icon:<WifiIcon />,  bg:"bg-cyan-100",  color:"text-cyan-500",  label:"Pusher Status",      value:"Connected", sub:"Real-time active",         dot:true  },
              { icon:<DbIcon />,    bg:"bg-blue-100",  color:"text-blue-600",  label:"Database",           value:"MySQL 8.0", sub:"Connected",                dot:true  },
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