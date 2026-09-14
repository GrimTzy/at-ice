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
import StockOutApprovalPage from './pages/admin/StockOutApprovalPage'

// ── Driver Module
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

// ── HR Module 
import FaceEnrollment from './pages/hr/FaceEnrollment'

// ── Employee Module 
import CheckerLogin from './pages/employees/CheckerLogin'
import CheckerDashboard from './pages/employees/CheckerDashboard'
import CheckerPickupLogPage from './pages/employees/CheckerPickupLogPage'
import CheckerIceLoadingPage from './pages/employees/CheckerIceLoadingPage'
import SalesmanLogin from './pages/employees/SalesmanLogin'
import SalesmanDashboard from './pages/employees/SalesmanDashboard'
import SalesmanDeliveryConfirmation from './pages/employees/SalesmanDeliveryConfirmation'
import MaintenanceLogin from './pages/employees/MaintenanceLogin'
import MaintenanceDashboard from './pages/employees/MaintenanceDashboard'
import MaintenanceStockOutRequestPage from './pages/employees/MaintenanceStockOutRequestPage'

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
        <Route
          path="/admin/stock-out-approvals"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <StockOutApprovalPage />
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
        <Route path="/checker/login" element={<CheckerLogin />} />
        <Route
          path="/checker/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/pickup-log"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerPickupLogPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/ice-loading"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerIceLoadingPage />
            </ProtectedRoute>
          }
        />
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
        <Route path="/maintenance/login" element={<MaintenanceLogin />} />
        <Route
          path="/maintenance/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <MaintenanceDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/maintenance/stock-out-request"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <MaintenanceStockOutRequestPage />
            </ProtectedRoute>
          }
        />

      </Routes>
    </BrowserRouter>
  )
}

export default App