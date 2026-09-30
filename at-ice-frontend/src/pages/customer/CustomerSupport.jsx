import CustomerLayout from "../../components/CustomerLayout";

const CONTACT = {
  office: "Bella Erin Tube Ice Inc.",
  address: "Block 63, Lot 3 Anubing St, Amparo Subdivision, Barangay 179 Zone 16, Caloocan City North, 1425, Metro Manila",
  phone: "0926 011 6955",
  email: "bellaerin78@gmail.com",
  hours: "Open 24 hours",
};

function Row({ label, value }) {
  return (
    <div className="px-5 py-4">
      <div className="text-[11px] text-slate-400">{label}</div>
      {value ? (
        <div className="text-sm text-slate-800 mt-0.5">{value}</div>
      ) : (
        <div className="text-sm text-slate-400 mt-0.5">To be announced</div>
      )}
    </div>
  );
}

export default function CustomerSupport() {
  return (
    <CustomerLayout pageTitle="Contact Support" breadcrumb="Support">
      <div className="bg-white border border-black/[0.09] rounded-md overflow-hidden max-w-2xl">
        <div className="px-5 py-3.5 border-b border-black/[0.09]">
          <div className="text-[13px] font-semibold text-slate-800">Reach the Bella Erin office</div>
          <div className="text-[11px] text-slate-400 mt-0.5">
            For questions about deliveries, receipts, or prices, contact the office directly.
          </div>
        </div>
        <div className="divide-y divide-black/[0.06]">
          <Row label="Company" value={CONTACT.office} />
          <Row label="Phone" value={CONTACT.phone} />
          <Row label="Email" value={CONTACT.email} />
          <Row label="Office hours" value={CONTACT.hours} />
          <Row label="Address" value={CONTACT.address} />
        </div>
      </div>
      <p className="text-[11px] text-slate-400 px-1 max-w-2xl">
        When you contact us, mention your delivery number or receipt number so we can find your record quickly.
      </p>
    </CustomerLayout>
  );
}