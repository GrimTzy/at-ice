import { typedDigits, PHONE_PREFIX } from "../lib/phone";

// Phone input with a fixed "+63" prefix beside it. `value` holds only the digits
// after +63 (see lib/phone.js); `onChange` receives the cleaned digits.
export default function PhoneInput({ value, onChange, className = "", invalid = false, placeholder = "9171234567", ...rest }) {
  return (
    <div className="flex items-stretch w-full">
      <span className="flex items-center px-2.5 rounded-l-md border border-r-0 border-black/[0.09] bg-slate-100 text-[13px] text-slate-500 select-none">
        {PHONE_PREFIX}
      </span>
      <input
        type="tel"
        inputMode="numeric"
        autoComplete="off"
        value={value}
        onChange={(e) => onChange(typedDigits(e.target.value))}
        placeholder={placeholder}
        className={`flex-1 min-w-0 rounded-l-none ${className} ${invalid ? "border-red-300 focus:border-red-400" : ""}`}
        {...rest}
      />
    </div>
  );
}
