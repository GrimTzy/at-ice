import { BrowserRouter, Routes, Route } from 'react-router-dom'

// ── General Pages ──────────────────────────────────────────────────────────
import LandingPage from './pages/LandingPage'
import LoginPage from './pages/LoginPage'
import AttendanceStation from './pages/AttendanceStation'
import LandingPageCustomer from './pages/LandingPage-customer'

// ── Admin Module ───────────────────────────────────────────────────────────
import AdminLogin from './pages/admin/AdminLogin'
import Dashboard from './pages/admin/Dashboard'

// ── Driver Module ──────────────────────────────────────────────────────────
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

// ── Employee Module ────────────────────────────────────────────────────────
// import CheckerLogin from './pages/employees/CheckerLogin'       // Phase 13
// import SalesmanLogin from './pages/employees/SalesmanLogin'     // Phase 13
// import MaintenanceLogin from './pages/employees/MaintenanceLogin' // Phase 13

function App() {
  return (
    <BrowserRouter>
      <Routes>

        {/* ── General ── */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/attendance-station" element={<AttendanceStation />} />
        <Route path="/customer" element={<LandingPageCustomer />} />

        {/* ── Admin Module ── */}
        <Route path="/admin/login" element={<AdminLogin />} />
        <Route path="/dashboard" element={<Dashboard />} />

        {/* ── Driver Module ── */}
        <Route path="/driver/login" element={<DriverLogin />} />
        <Route path="/driver/deliveries" element={<DeliveriesPage />} />
        <Route path="/driver/gps" element={<GpsTrackingPage />} />

        {/* ── Employee Module (Phase 13) ── */}
        {/* <Route path="/checker/login" element={<CheckerLogin />} /> */}
        {/* <Route path="/salesman/login" element={<SalesmanLogin />} /> */}
        {/* <Route path="/maintenance/login" element={<MaintenanceLogin />} /> */}

      </Routes>
    </BrowserRouter>
  )
}

export default App