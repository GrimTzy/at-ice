import { BrowserRouter, Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'

// ── General Pages
import LandingPage from './pages/LandingPage'
import LoginPage from './pages/LoginPage'
import AttendanceStation from './pages/AttendanceStation'
import LandingPageCustomer from './pages/LandingPage-customer'

// ── Admin Module
import AdminLogin from './pages/admin/AdminLogin'
import Dashboard from './pages/admin/Dashboard'
import CustomerPriceManagement from './pages/admin/CustomerPriceManagement'

// ── Driver Module
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

// ── HR Module 
import FaceEnrollment from './pages/hr/FaceEnrollment'

// ── Employee Module 
// import CheckerLogin from './pages/employees/CheckerLogin'       // Phase 13
import SalesmanLogin from './pages/employees/SalesmanLogin'
import SalesmanDashboard from './pages/employees/SalesmanDashboard'
import SalesmanDeliveryConfirmation from './pages/employees/SalesmanDeliveryConfirmation'
// import MaintenanceLogin from './pages/employees/MaintenanceLogin' // Phase 13

function App() {
  return (
    <BrowserRouter>
      <Routes>

        {/* ── General (public, no auth needed) ── */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/attendance-station" element={<AttendanceStation />} />
        <Route path="/customer" element={<LandingPageCustomer />} />

        {/* ── Admin Module ── */}
        <Route path="/admin/login" element={<AdminLogin />} />
        <Route
          path="/dashboard"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <Dashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin/customer-prices"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <CustomerPriceManagement />
            </ProtectedRoute>
          }
        />

        {/* ── HR Module ── */}
        <Route
          path="/hr/face-enrollment"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <FaceEnrollment />
            </ProtectedRoute>
          }
        />

        {/* ── Driver Module ── */}
        <Route path="/driver/login" element={<DriverLogin />} />
        <Route
          path="/driver/deliveries"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <DeliveriesPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/driver/gps"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <GpsTrackingPage />
            </ProtectedRoute>
          }
        />

        {/* ── Employee Module (Phase 13) ── */}
        {/* <Route path="/checker/login" element={<CheckerLogin />} /> */}
        <Route path="/salesman/login" element={<SalesmanLogin />} />
        <Route
          path="/salesman/deliveries"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <SalesmanDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/salesman/deliveries/:id/confirm"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <SalesmanDeliveryConfirmation />
            </ProtectedRoute>
          }
        />
        {/* <Route path="/maintenance/login" element={<MaintenanceLogin />} /> */}

      </Routes>
    </BrowserRouter>
  )
}

export default App