import { BrowserRouter, Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'

// -- General Pages
import LandingPage from './pages/LandingPage'
import LoginPage from './pages/LoginPage'
import AttendanceStation from './pages/AttendanceStation'
import LandingPageCustomer from './pages/LandingPage-customer'

// -- Admin Module
import AdminLogin from './pages/admin/AdminLogin'
import Dashboard from './pages/admin/Dashboard'
import CustomerPriceManagement from './pages/admin/CustomerPriceManagement'
import StockOutApprovalPage from './pages/Admin/StockOutApprovalPage'
import EmployeesOverviewPage from './pages/Admin/EmployeesOverviewPage'
import CustomersPage from './pages/Admin/CustomersPage'
import DeliveriesPage from './pages/Admin/DeliveriesPage'
import ReceiptsPage from './pages/Admin/ReceiptsPage'

// -- Driver Module
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage as DriverDeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

// -- HR Module 
import HRLogin from './pages/hr/HRLogin'
import HRDashboard from './pages/hr/HRDashboard'
import FaceEnrollment from './pages/hr/FaceEnrollment'
import EmployeesPage from './pages/hr/EmployeesPage'

// -- Employee Module 
import CheckerLogin from './pages/employees/CheckerLogin'
import CheckerDashboard from './pages/employees/CheckerDashboard'
import CheckerPickupLogPage from './pages/employees/CheckerPickupLogPage'
import CheckerIceLoadingPage from './pages/employees/CheckerIceLoadingPage'
import CheckerInventoryManagementPage from './pages/employees/CheckerInventoryManagementPage'
import CheckerStockMovementsPage from './pages/employees/CheckerStockMovementsPage'
import CheckerSupplierManagementPage from './pages/employees/CheckerSupplierManagementPage'
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

        {/* -- General (public, no auth needed) -- */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/attendance-station" element={<AttendanceStation />} />
        <Route path="/customer" element={<LandingPageCustomer />} />

        {/* -- Admin Module -- */}
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
        {/* View-only Employees overview for Admin — Name/Position/Role/Shift
            only, backed by GET /api/employees-summary. Full CRUD + Payroll
            + Performance live under HR at /hr/employees below. */}
        <Route
          path="/admin/employees"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <EmployeesOverviewPage />
            </ProtectedRoute>
          }
        />
        {/* Customers — full CRUD for Customer CRM records, plus a
            "Create Login" action per row that provisions a Customer
            Portal account (role: customer) for an existing record. */}
        <Route
          path="/admin/customers"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <CustomersPage />
            </ProtectedRoute>
          }
        />
        {/* Deliveries — full CRUD for outbound Deliveries: Admin assigns
            driver + customer + line items. */}
        <Route
          path="/admin/deliveries"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <DeliveriesPage />
            </ProtectedRoute>
          }
        />
        {/* Receipts — auto-generated on Salesman delivery confirmation.
            Admin tracks payment status here and marks receipts paid. */}
        <Route
          path="/admin/receipts"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <ReceiptsPage />
            </ProtectedRoute>
          }
        />

        {/* -- HR Module -- */}
        <Route path="/hr/login" element={<HRLogin />} />
        <Route
          path="/hr/dashboard"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <HRDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/hr/face-enrollment"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <FaceEnrollment />
            </ProtectedRoute>
          }
        />
        <Route
          path="/hr/employees"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <EmployeesPage />
            </ProtectedRoute>
          }
        />

        {/* -- Driver Module -- */}
        <Route path="/driver/login" element={<DriverLogin />} />
        <Route
          path="/driver/deliveries"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <DriverDeliveriesPage />
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

        {/* -- Employee Module (Phase 13) -- */}
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
        <Route
          path="/checker/inventory"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerInventoryManagementPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/stock-movements"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerStockMovementsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/suppliers"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <CheckerSupplierManagementPage />
            </ProtectedRoute>
          }
        />
        {/* /checker/low-stock and /checker/stock-out-release routes REMOVED — see notes above */}
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