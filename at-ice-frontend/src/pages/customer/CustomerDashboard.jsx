// src/pages/customer/CustomerDashboard.jsx
//
// Real Dashboard: profile card (from GET /api/my-profile) plus summary
// counts computed client-side from GET /api/my-delivery-history and
// GET /api/my-receipts. No separate backend summary endpoint needed for
// this — the customer's own delivery/receipt lists are small enough that
// counting on the frontend is simpler and one less endpoint to maintain.

import { useEffect, useState } from "react";
import CustomerLayout from "../../components/CustomerLayout";

const authHeaders = () => ({
  Accept: "application/json",
  Authorization: `Bearer ${localStorage.getItem("token")}`,
});

const peso = (n) =>
  "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const STATUS_LABEL = {
  pending: "Pending",
  in_transit: "In Transit",
  delivered: "Delivered",
  cancelled: "Cancelled",
};

const STATUS_BADGE = {
  pending: "bg-amber-500/10 text-amber-600 border-amber-500/20",
  in_transit: "bg-cyan-500/10 text-cyan-600 border-cyan-500/20",
  delivered: "bg-emerald-500/10 text-emerald-600 border-emerald-500/20",
  cancelled: "bg-slate-200 text-slate-500 border-slate-300",
};

function StatCard({ label, value, sub }) {
  return (
    <div className="bg-white border border-black/[0.09] rounded-md p-4">
      <div className="text-[11px] text-slate-400 uppercase tracking-wide">{label}</div>
      <div className="text-xl font-semibold text-slate-800 mt-1">{value}</div>
      {sub && <div className="text-[11px] text-slate-400 mt-0.5">{sub}</div>}
    </div>
  );
}

export default function CustomerDashboard() {
  const [profile, setProfile] = useState(null);
  const [deliveries, setDeliveries] = useState([]);
  const [receipts, setReceipts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const load = async () => {
      try {
        const [profileRes, deliveriesRes, receiptsRes] = await Promise.all([
          fetch("/api/my-profile", { headers: authHeaders() }),
          fetch("/api/my-delivery-history", { headers: authHeaders() }),
          fetch("/api/my-receipts", { headers: authHeaders() }),
        ]);

        if (!profileRes.ok) throw new Error(`Could not load profile (${profileRes.status})`);
        if (!deliveriesRes.ok) throw new Error(`Could not load deliveries (${deliveriesRes.status})`);
        if (!receiptsRes.ok) throw new Error(`Could not load receipts (${receiptsRes.status})`);

        setProfile(await profileRes.json());
        setDeliveries(await deliveriesRes.json());
        setReceipts(await receiptsRes.json());
      } catch (err) {
        setError(err.message || "Something went wrong loading your dashboard.");
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  const pendingCount = deliveries.filter((d) => d.status === "pending").length;
  const inTransitCount = deliveries.filter((d) => d.status === "in_transit").length;
  const deliveredCount = deliveries.filter((d) => d.status === "delivered").length;

  const balanceDue = receipts
    .filter((r) => r.payment_status !== "paid")
    .reduce((sum, r) => sum + Number(r.total_amount || 0), 0);
  const overdueCount = receipts.filter((r) => r.payment_status === "overdue").length;

  const recentDeliveries = deliveries.slice(0, 5);
  const recentReceipts = receipts.slice(0, 5);

  if (loading) {
    return (
      <CustomerLayout pageTitle="Dashboard" breadcrumb="Overview">
        <div className="bg-white border border-black/[0.09] rounded-md p-8 text-center text-sm text-slate-400">
          Loading your dashboard...
        </div>
      </CustomerLayout>
    );
  }

  if (error) {
    return (
      <CustomerLayout pageTitle="Dashboard" breadcrumb="Overview">
        <div className="bg-red-500/[0.06] border border-red-500/20 rounded-md p-6 text-sm text-red-600">
          {error}
        </div>
      </CustomerLayout>
    );
  }

  return (
    <CustomerLayout pageTitle="Dashboard" breadcrumb="Overview">
      {/* Profile card */}
      <div className="bg-white border border-black/[0.09] rounded-md px-5 py-4">
        <div className="text-[15px] font-semibold text-slate-800">{profile?.name}</div>
        <div className="text-xs text-slate-500 mt-1 flex flex-wrap gap-x-4 gap-y-1">
          {profile?.address && <span>{profile.address}{profile?.city ? `, ${profile.city}` : ""}{profile?.province ? `, ${profile.province}` : ""}</span>}
          {profile?.contact_person && <span>Contact: {profile.contact_person}</span>}
          {profile?.contact_number && <span>{profile.contact_number}</span>}
        </div>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Pending" value={pendingCount} sub="Not yet dispatched" />
        <StatCard label="In Transit" value={inTransitCount} sub="On the way" />
        <StatCard label="Delivered" value={deliveredCount} sub="Completed" />
        <StatCard
          label="Balance Due"
          value={peso(balanceDue)}
          sub={overdueCount > 0 ? `${overdueCount} overdue` : "All current"}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Recent deliveries */}
        <div className="bg-white border border-black/[0.09] rounded-md">
          <div className="px-5 py-3.5 border-b border-black/[0.09] text-[13px] font-semibold text-slate-800">
            Recent Deliveries
          </div>
          {recentDeliveries.length === 0 ? (
            <div className="px-5 py-6 text-center text-xs text-slate-400">No deliveries yet.</div>
          ) : (
            <div className="divide-y divide-black/[0.06]">
              {recentDeliveries.map((d) => (
                <div key={d.id} className="px-5 py-3 flex items-center justify-between">
                  <div>
                    <div className="text-xs font-medium text-slate-800">{d.delivery_no}</div>
                    <div className="text-[11px] text-slate-400">{d.driver_name || "Driver not yet assigned"}</div>
                  </div>
                  <span className={`px-2 py-0.5 rounded-full text-[11px] font-medium border ${STATUS_BADGE[d.status] || ""}`}>
                    {STATUS_LABEL[d.status] || d.status}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Recent receipts */}
        <div className="bg-white border border-black/[0.09] rounded-md">
          <div className="px-5 py-3.5 border-b border-black/[0.09] text-[13px] font-semibold text-slate-800">
            Recent Receipts
          </div>
          {recentReceipts.length === 0 ? (
            <div className="px-5 py-6 text-center text-xs text-slate-400">No receipts yet.</div>
          ) : (
            <div className="divide-y divide-black/[0.06]">
              {recentReceipts.map((r) => (
                <div key={r.id} className="px-5 py-3 flex items-center justify-between">
                  <div>
                    <div className="text-xs font-medium text-slate-800">{r.receipt_no}</div>
                    <div className="text-[11px] text-slate-400">{peso(r.total_amount)}</div>
                  </div>
                  <span className={`px-2 py-0.5 rounded-full text-[11px] font-medium border ${
                    r.payment_status === "paid" ? "bg-emerald-500/10 text-emerald-600 border-emerald-500/20"
                    : r.payment_status === "overdue" ? "bg-red-500/10 text-red-600 border-red-500/20"
                    : "bg-amber-500/10 text-amber-600 border-amber-500/20"
                  }`}>
                    {r.payment_status.charAt(0).toUpperCase() + r.payment_status.slice(1)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </CustomerLayout>
  );
}