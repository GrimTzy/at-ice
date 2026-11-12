import { Capacitor } from "@capacitor/core";

// True inside the AT-ICE Android app (Capacitor), false in a browser.
export const isNativeApp = () => Capacitor.isNativePlatform();

// The app is for the four mobile roles only (Driver, Checker, Salesman,
// Maintenance). Admin, HR and Customer use the website.
export const isMobileRole = (role, subRole) =>
  role === "driver" || (role === "employee" && ["checker", "salesman", "maintenance"].includes(subRole));
