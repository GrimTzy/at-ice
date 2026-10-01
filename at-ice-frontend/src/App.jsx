import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import { NightScope } from './components/NightMode'

// -- General Pages
import PortalPage from './pages/PortalPage'
import AttendanceStation from './pages/AttendanceStation'

// -- Admin Module (folder is "Admin" with a capital A: Linux servers are
// case-sensitive, so every import must match it exactly)
import AdminLogin from './pages/Admin/AdminLogin'
import Dashboard from './pages/Admin/Dashboard'
import CustomerPriceManagement from './pages/Admin/CustomerPriceManagement'
import StockOutApprovalPage from './pages/Admin/StockOutApprovalPage'
import EmployeesOverviewPage from './pages/Admin/EmployeesOverviewPage'
import CustomersPage from './pages/Admin/CustomersPage'
import DeliveriesPage from './pages/Admin/DeliveriesPage'
import DeliveryProofsPage from './pages/Admin/DeliveryProofsPage'
import ReceiptsPage from './pages/Admin/ReceiptsPage'
import SettingsPage from './pages/Admin/SettingsPage'

// -- Customer Module
import CustomerLogin from './pages/customer/CustomerLogin'
import CustomerDashboard from './pages/customer/CustomerDashboard'
import CustomerDeliveries from './pages/customer/CustomerDeliveries'
import CustomerDeliveryProofs from './pages/customer/CustomerDeliveryProofs'
import CustomerReceipts from './pages/customer/CustomerReceipts'
import CustomerPrices from './pages/customer/CustomerPrices'
import CustomerSupport from './pages/customer/CustomerSupport'

// -- Driver Module
import DriverLogin from './pages/drivers/DriverLogin'
import { DeliveriesPage as DriverDeliveriesPage } from './pages/drivers/DeliveriesPage'
import { GpsTrackingPage } from './pages/drivers/GpsTrackingPage'

// -- HR Module
import HRLogin from './pages/hr/HRLogin'
import HRDashboard from './pages/hr/HRDashboard'
import FaceEnrollment from './pages/hr/FaceEnrollment'
import EmployeesPage from './pages/hr/EmployeesPage'
import AttendancePage from './pages/hr/AttendancePage'
import LeaveRequestsPage from './pages/hr/LeaveRequestsPage'
import InterviewsPage from './pages/hr/InterviewsPage'
import PayrollPage from './pages/hr/PayrollPage'

// -- Employee Module
import CheckerLogin from './pages/employees/CheckerLogin'
import CheckerDashboard from './pages/employees/CheckerDashboard'
import CheckerPickupLogPage from './pages/employees/CheckerPickupLogPage'
import CheckerIceLoadingPage from './pages/employees/CheckerIceLoadingPage'
import CheckerInventoryManagementPage from './pages/employees/CheckerInventoryManagementPage'
import CheckerStockMovementsPage from './pages/employees/CheckerStockMovementsPage'
import CheckerSupplierManagementPage from './pages/employees/CheckerSupplierManagementPage'
import CheckerStockOutReleasePage from './pages/employees/CheckerStockOutReleasePage'
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
        {/* Portal: one card per module. "/login" is also ProtectedRoute's
            last-resort fallback, so it shows the portal too. */}
        <Route path="/" element={<PortalPage />} />
        <Route path="/login" element={<PortalPage />} />
        <Route path="/attendance-station" element={<AttendanceStation />} />

        {/* -- Customer Module -- */}
        <Route path="/customer/login" element={<CustomerLogin />} />
        <Route
          path="/customer/dashboard"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/customer/deliveries"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerDeliveries />
            </ProtectedRoute>
          }
        />
        <Route
          path="/customer/delivery-proofs"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerDeliveryProofs />
            </ProtectedRoute>
          }
        />
        <Route
          path="/customer/receipts"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerReceipts />
            </ProtectedRoute>
          }
        />
        <Route
          path="/customer/prices"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerPrices />
            </ProtectedRoute>
          }
        />
        <Route
          path="/customer/support"
          element={
            <ProtectedRoute allowedRoles={["customer"]}>
              <CustomerSupport />
            </ProtectedRoute>
          }
        />

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
        {/* Stock-Out Approvals: Admin approves or rejects Maintenance
            requests. Release is done by the Checker. */}
        <Route
          path="/admin/stock-out-approvals"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <StockOutApprovalPage />
            </ProtectedRoute>
          }
        />
        {/* View-only Employees overview for Admin: Name/Position/Role/Shift
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
        {/* Customers: full CRUD for Customer CRM records, plus a
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
        {/* Deliveries: full CRUD for outbound Deliveries. Admin assigns
            driver + customer + line items. */}
        <Route
          path="/admin/deliveries"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <DeliveriesPage />
            </ProtectedRoute>
          }
        />
        {/* Delivery Proofs: view-only. Photo + quantity confirmed by
            Salesmen, with the auto-generated receipt breakdown. */}
        <Route
          path="/admin/delivery-proofs"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <DeliveryProofsPage />
            </ProtectedRoute>
          }
        />
        {/* Receipts: auto-generated on Salesman delivery confirmation.
            Admin tracks payment status here and marks receipts paid. */}
        <Route
          path="/admin/receipts"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <ReceiptsPage />
            </ProtectedRoute>
          }
        />
        {/* Settings: account only. Profile, change password,
            sign out other devices. */}
        <Route
          path="/admin/settings"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <SettingsPage />
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
        {/* Attendance: view only, records come from the Attendance
            Station kiosk. Date filter, search, live Pusher refresh. */}
        <Route
          path="/hr/attendance"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <AttendancePage />
            </ProtectedRoute>
          }
        />
        {/* Leave Requests: HR encodes the employee's verbal or written
            request, then approves or rejects it. */}
        <Route
          path="/hr/leave-requests"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <LeaveRequestsPage />
            </ProtectedRoute>
          }
        />
        {/* Interviews: applicants and interview schedule.
            Pending -> Interviewed -> Hired / Rejected. */}
        <Route
          path="/hr/interviews"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <InterviewsPage />
            </ProtectedRoute>
          }
        />
        {/* Payroll: all payroll records (computed in the Employees page,
            Sweldong Pinoy reference). Pending -> Approved -> Paid. */}
        <Route
          path="/hr/payroll"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <PayrollPage />
            </ProtectedRoute>
          }
        />

        {/* -- Driver Module (night mode through NightScope) -- */}
        <Route path="/driver/login" element={<DriverLogin />} />
        <Route
          path="/driver/deliveries"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <NightScope><DriverDeliveriesPage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/driver/gps"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <NightScope><GpsTrackingPage /></NightScope>
            </ProtectedRoute>
          }
        />

        {/* -- Employee Module (Phase 13, night mode through NightScope) -- */}
        <Route path="/checker/login" element={<CheckerLogin />} />
        <Route
          path="/checker/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerDashboard /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/pickup-log"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerPickupLogPage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/ice-loading"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerIceLoadingPage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/inventory"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerInventoryManagementPage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/stock-movements"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerStockMovementsPage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/suppliers"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerSupplierManagementPage /></NightScope>
            </ProtectedRoute>
          }
        />
        {/* Stock-Out Release: Checker releases Admin-approved Maintenance
            requests; stock is deducted on release. (/checker/low-stock stays
            removed: Low Stock Alert is a tab in Inventory Management.) */}
        <Route
          path="/checker/stock-out-release"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <NightScope><CheckerStockOutReleasePage /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route path="/salesman/login" element={<SalesmanLogin />} />
        <Route
          path="/salesman/deliveries"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <NightScope><SalesmanDashboard /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/salesman/deliveries/:id/confirm"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <NightScope><SalesmanDeliveryConfirmation /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route path="/maintenance/login" element={<MaintenanceLogin />} />
        <Route
          path="/maintenance/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <NightScope><MaintenanceDashboard /></NightScope>
            </ProtectedRoute>
          }
        />
        <Route
          path="/maintenance/stock-out-request"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <NightScope><MaintenanceStockOutRequestPage /></NightScope>
            </ProtectedRoute>
          }
        />

        {/* Any unknown URL goes to the portal instead of a blank screen */}
        <Route path="*" element={<Navigate to="/" replace />} />

      </Routes>
    </BrowserRouter>
  )
}

export default App