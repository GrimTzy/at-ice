import { useEffect, useState } from "react";
import CustomerLayout from "../../components/CustomerLayout";

const authHeaders = () => ({
  Accept: "application/json",
  Authorization: `Bearer ${localStorage.getItem("token")}`,
});

const peso = (n) =>
  "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const PRODUCTS = [
  { key: "bags", name: "Bags", size: "5kg" },
  { key: "small_sack", name: "Small Sack", size: "30kg" },
  { key: "big_sack", name: "Big Sack", size: "50kg" },
];

export default function CustomerPrices() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const load = async () => {
      try {
        const res = await fetch("/api/my-prices", { headers: authHeaders() });
        if (!res.ok) throw new Error(`Could not load prices (${res.status})`);
        setData(await res.json());
      } catch (err) {
        setError(err.message || "Something went wrong loading your prices.");
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  return (
    <CustomerLayout pageTitle="My Prices" breadcrumb="Billing">
      {loading ? (
        <div className="bg-white border border-black/[0.09] rounded-md p-8 text-center text-sm text-slate-400">
          Loading your prices...
        </div>
      ) : error ? (
        <div className="bg-red-500/[0.06] border border-red-500/20 rounded-md p-6 text-sm text-red-600">{error}</div>
      ) : (
        <>
          <div className="bg-white border border-black/[0.09] rounded-md overflow-hidden">
            <div className="px-5 py-3.5 border-b border-black/[0.09]">
              <div className="text-[13px] font-semibold text-slate-800">Your ice prices</div>
              <div className="text-[11px] text-slate-400 mt-0.5">
                Rates agreed for {data?.customer_name || "your account"}, per unit.
              </div>
            </div>
            <div className="divide-y divide-black/[0.06]">
              {PRODUCTS.map((p) => {
                const price = data?.prices?.[p.key];
                return (
                  <div key={p.key} className="px-5 py-4 flex items-center justify-between">
                    <div>
                      <div className="text-sm font-medium text-slate-800">{p.name}</div>
                      <div className="text-[11px] text-slate-400">{p.size}</div>
                    </div>
                    {price === null || price === undefined ? (
                      <div className="text-xs text-slate-400">Not set yet</div>
                    ) : (
                      <div className="text-lg font-semibold text-slate-800">{peso(price)}</div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
          <p className="text-[11px] text-slate-400 px-1">
            Prices are set by the Bella Erin office. To ask about a rate, see Contact Support in the menu.
          </p>
        </>
      )}
    </CustomerLayout>
  );
}