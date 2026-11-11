import { Navigate, useParams } from "react-router-dom";
import HrLayout from "../../components/HrLayout";
import { RatesSection, PayRates } from "../../components/SystemSettings";
import { TipsWidget } from "../../components/Insight";

// HR > Rates and rules: one page per section (/hr/rates/{page}), each opened
// from its own item of the Rates and rules menu in the sidebar (lib/nav.jsx;
// that menu opens only on hover). Each page loads only its own settings
// (GET /api/settings/{page}).
// Moved here from Admin > Settings (owner, 2026-11-09); HR only.
// Kept with Admin: HR kiosk unlock and the record expiry action; Deliveries and
// Support tickets are in Admin > Settings (owner, 2026-11-10).
const PAGES = [
  { key: "payroll", label: "Payroll", hint: "Pay period, paid hours, the daily-rate divisor, deductions and the night differential." },
  { key: "attendance", label: "Attendance", hint: "Default shift hours, break, grace minutes and work days." },
  { key: "leave", label: "Leave", hint: "Leave credits and how early sick and vacation leave are filed." },
  { key: "kiosk", label: "Kiosk", hint: "Wrong PINs before a kiosk or a network address is locked." },
  { key: "memos", label: "Disciplinary memos", hint: "Missing Time Out drafts and how long an offense counts." },
  { key: "thirteenth", label: "13th month", hint: "The date the 13th month pay is credited." },
  { key: "records", label: "Records", hint: "A month after deactivation the login, face, documents and personal details are deleted; the attendance, leave and payroll records stay 3 years (DOLE), then are anonymized." },
  { key: "pay-rates", label: "Holiday and overtime pay", hint: "Holiday and overtime rates, each from a date; past payrolls keep their rates." },
];

const TIPS = {
  "pay-rates": [
    { title: "Rates are dated", text: "A new rate applies from its date; past payrolls keep the rate of their day.", tone: "cyan", icon: "calendar" },
  ],
};
const COMMON_TIP = { title: "Every change is audited", text: "The old and new value and who changed it are kept in the audit log.", tone: "violet", icon: "file" };

export default function RatesRulesPage() {
  const { section } = useParams();
  const page = PAGES.find((p) => p.key === section);
  if (!page) return <Navigate to="/hr/rates/payroll" replace />;

  return (
    <HrLayout pageTitle={`Rates and rules · ${page.label}`} breadcrumb={`Rates and rules / ${page.label}`}>
      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
        <div className="xl:col-span-8 flex flex-col gap-4 min-w-0">
          <section className="bg-white border border-black/[0.09] rounded-xl p-[18px]" aria-labelledby="rates-page-title">
            <h2 id="rates-page-title" className="text-[15px] font-semibold text-slate-800">{page.label}</h2>
            <p className="text-[12px] text-slate-500 mt-0.5 mb-2">{page.hint} Each change is recorded in the audit log.</p>
            {page.key === "pay-rates" ? <PayRates /> : <RatesSection key={page.key} page={page.key} />}
          </section>
        </div>
        <aside className="xl:col-span-4 flex flex-col gap-4 min-w-0 xl:sticky xl:top-0">
          <TipsWidget tips={[...(TIPS[page.key] || []), COMMON_TIP]} />
        </aside>
      </div>
    </HrLayout>
  );
}
