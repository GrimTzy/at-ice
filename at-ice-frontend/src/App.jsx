import { BrowserRouter, Routes, Route } from 'react-router-dom'
import LandingPage from './pages/LandingPage'
import LoginPage from './pages/LoginPage'
import AttendanceStation from './pages/AttendanceStation'
import LandingPageCustomer from './pages/LandingPage-customer'
import Dashboard from './pages/Dashboard'

// Driver Module
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        {/* ── Existing Routes ── */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/attendance-station" element={<AttendanceStation />} />
        <Route path="/customer" element={<LandingPageCustomer />} />
        <Route path="/dashboard" element={<Dashboard />} />

        {/* ── Driver Module ── */}
        <Route path="/driver/login" element={<DriverLogin />} />
        <Route path="/driver/deliveries" element={<DeliveriesPage />} />
        <Route path="/driver/gps" element={<GpsTrackingPage />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App