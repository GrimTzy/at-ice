import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import { NightScope } from './components/NightMode'
import MobileAppShell from './components/MobileAppShell'
import LightOnly from './components/LightOnly'
import SplashScreen from './components/SplashScreen'
import AppUpdatePrompt from './components/AppUpdatePrompt'
import SoftPickers from './components/SoftPickers'
import Toaster from './components/Toaster'
import ActionGuard from './components/ActionGuard'
import AttachmentViewer from './components/AttachmentViewer'
import OverflowTitle from './components/OverflowTitle'
import IdleGuard from './components/IdleGuard'
import RouteLoading from './components/RouteLoading'
import { DestinationWatcher } from './lib/destination'
import { installHeroPulse } from './lib/heroPulse'
installHeroPulse()

// -- General Pages
import LoginPage from './pages/LoginPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
const ApplyPage = lazy(() => import('./pages/ApplyPage'))
const AttendanceStation = lazy(() => import('./pages/AttendanceStation'))
import KioskGate from './components/KioskGate'
const LeaveRequestPage = lazy(() => import('./pages/LeaveRequestPage'))

// -- Admin Module (folder is "Admin" with a capital A: Linux servers are
// case-sensitive, so every import must match it exactly)
const Dashboard = lazy(() => import('./pages/Admin/Dashboard'))
const CustomerPriceManagement = lazy(() => import('./pages/Admin/CustomerPriceManagement'))
const StockOutApprovalPage = lazy(() => import('./pages/Admin/StockOutApprovalPage'))
const StockLimitsPage = lazy(() => import('./pages/Admin/StockLimitsPage'))
const ProjectsPage = lazy(() => import('./pages/Admin/ProjectsPage'))
const MaintenanceProjectsPage = lazy(() => import('./pages/employees/MaintenanceProjectsPage'))
const EmployeesOverviewPage = lazy(() => import('./pages/Admin/EmployeesOverviewPage'))
const PerformancePage = lazy(() => import('./pages/Admin/PerformancePage'))
const PerformanceHistoryPage = lazy(() => import('./pages/Admin/PerformanceHistoryPage'))
const MemoApprovalsPage = lazy(() => import('./pages/Admin/MemoApprovalsPage'))
const CustomersPage = lazy(() => import('./pages/Admin/CustomersPage'))
const DeliveriesPage = lazy(() => import('./pages/Admin/DeliveriesPage'))
const ReceiptsPage = lazy(() => import('./pages/Admin/ReceiptsPage'))
const SupportInboxPage = lazy(() => import('./pages/Admin/SupportInboxPage'))
const SettingsPage = lazy(() => import('./pages/Admin/SettingsPage'))

// -- Customer Module
const ChangePasswordPage = lazy(() => import('./pages/ChangePasswordPage'))
const CustomerDashboard = lazy(() => import('./pages/customer/CustomerDashboard'))
const CustomerDeliveries = lazy(() => import('./pages/customer/CustomerDeliveries'))
const CustomerDeliveryProofs = lazy(() => import('./pages/customer/CustomerDeliveryProofs'))
const CustomerReceipts = lazy(() => import('./pages/customer/CustomerReceipts'))
const CustomerPrices = lazy(() => import('./pages/customer/CustomerPrices'))
const CustomerSupport = lazy(() => import('./pages/customer/CustomerSupport'))

// -- Driver Module
const DriverDeliveriesPage = lazy(() => import('./pages/drivers/DeliveriesPage').then((m) => ({ default: m.DeliveriesPage })))
const GpsTrackingPage = lazy(() => import('./pages/drivers/GpsTrackingPage').then((m) => ({ default: m.GpsTrackingPage })))

// -- HR Module
const HRDashboard = lazy(() => import('./pages/hr/HRDashboard'))
const EmployeesPage = lazy(() => import('./pages/hr/EmployeesPage'))
const AttendancePage = lazy(() => import('./pages/hr/AttendancePage'))
const LeaveRequestsPage = lazy(() => import('./pages/hr/LeaveRequestsPage'))
const MemoManager = lazy(() => import('./pages/hr/MemoManager'))
const InterviewsPage = lazy(() => import('./pages/hr/InterviewsPage'))
const TimesheetsPage = lazy(() => import('./pages/hr/TimesheetsPage'))
const HolidaysPage = lazy(() => import('./pages/hr/HolidaysPage'))
const PayrollRecordsPage = lazy(() => import('./pages/hr/PayrollRecordsPage'))
const RatesRulesPage = lazy(() => import('./pages/hr/RatesRulesPage'))

// -- Employee Module
const CheckerDashboard = lazy(() => import('./pages/employees/CheckerDashboard'))
const CheckerIceLoadingPage = lazy(() => import('./pages/employees/CheckerIceLoadingPage'))
const CheckerInventoryManagementPage = lazy(() => import('./pages/employees/CheckerInventoryManagementPage'))
const CheckerStockMovementsPage = lazy(() => import('./pages/employees/CheckerStockMovementsPage'))
const CheckerSupplierManagementPage = lazy(() => import('./pages/employees/CheckerSupplierManagementPage'))
const SalesmanDashboard = lazy(() => import('./pages/employees/SalesmanDashboard'))
const SalesmanDeliveryConfirmation = lazy(() => import('./pages/employees/SalesmanDeliveryConfirmation'))
const MaintenanceDashboard = lazy(() => import('./pages/employees/MaintenanceDashboard'))
const MaintenanceStockOutRequestPage = lazy(() => import('./pages/employees/MaintenanceStockOutRequestPage'))


function App() {
  return (
    <BrowserRouter>
      {/* Intro once per browser tab session, over the page while it loads */}
      <SplashScreen />
      <SoftPickers />
      <Toaster />
      <AppUpdatePrompt />
      <ActionGuard />
      <AttachmentViewer />
      <OverflowTitle />
      <IdleGuard />
      <DestinationWatcher />
      {/* Pages load on demand (React.lazy); the loader fades in only if a page is slow */}
      <Suspense fallback={<RouteLoading />}>
      <Routes>

        {/* -- General (public, no auth needed) -- */}
        {/* One login page for every role. After login the user goes to their own
            dashboard, chosen by the role and sub_role the API returns. */}
        <Route path="/" element={<Navigate to="/login" replace />} />
        <Route path="/login" element={<LightOnly><LoginPage /></LightOnly>} />
        <Route path="/forgot-password" element={<LightOnly><ForgotPasswordPage /></LightOnly>} />
        {/* Public job application form, opened from the QR code in HRIS > Interviews */}
        <Route path="/apply" element={<LightOnly><ApplyPage /></LightOnly>} />
        {/* Old referral QR links open the general form (referrals were removed, QA review 2026-11-11) */}
        <Route path="/job-applications/:code" element={<Navigate to="/apply" replace />} />

        {/* Old per-role login links still work: they all go to the one login page. */}
        {["/admin/login", "/hr/login", "/driver/login", "/customer/login", "/checker/login", "/salesman/login", "/maintenance/login"].map((path) => (
          <Route key={path} path={path} element={<Navigate to="/login" replace />} />
        ))}
        <Route path="/attendance-station" element={<LightOnly><KioskGate><AttendanceStation /></KioskGate></LightOnly>} />

        {/* -- Leave Request (every Employee role and Driver, own requests only) -- */}
        <Route
          path="/my-leave"
          element={
            <ProtectedRoute allowedRoles={["employee", "driver"]}>
              <MobileAppShell><NightScope><LeaveRequestPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />

        {/* First-login password change, every role */}
        <Route
          path="/change-password"
          element={
            <ProtectedRoute>
              <ChangePasswordPage />
            </ProtectedRoute>
          }
        />
        <Route path="/customer/change-password" element={<Navigate to="/change-password" replace />} />

        {/* -- Customer Module -- */}
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
            requests, then releases the approved ones (stock is deducted
            on release). */}
        <Route
          path="/admin/stock-out-approvals"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <StockOutApprovalPage />
            </ProtectedRoute>
          }
        />
        {/* Minimum and maximum stock: Admin only (opened from Stock-Out Approvals). */}
        <Route
          path="/admin/stock-limits"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <StockLimitsPage />
            </ProtectedRoute>
          }
        />
        {/* Projects board (monday.com style): maintenance and plant jobs
            and the supplies their stock-out requests used. */}
        <Route
          path="/admin/projects"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <ProjectsPage />
            </ProtectedRoute>
          }
        />
        {/* View-only Employees overview for Admin: Name/Position/Role/Shift
            only, backed by GET /api/employees-summary. Full CRUD + Payroll
            live under HR at /hr/employees below. */}
        {/* Performance appraisals: draft ratings from the records, Admin only */}
        <Route
          path="/admin/performance"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <PerformancePage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin/performance/:employeeId"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <PerformanceHistoryPage />
            </ProtectedRoute>
          }
        />
        {/* Formal memos HR endorsed: Admin approves or returns them */}
        <Route
          path="/admin/memo-approvals"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <MemoApprovalsPage />
            </ProtectedRoute>
          }
        />
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
        {/* Delivery proofs are part of Deliveries now (Proof column and
            the View dialog); the old address opens the Delivered filter. */}
        <Route path="/admin/delivery-proofs" element={<Navigate to="/admin/deliveries?status=delivered" replace />} />
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
        {/* Support Tickets: customer Contact Support messages as tickets (dispatch room). */}
        <Route
          path="/admin/support-inbox"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <SupportInboxPage />
            </ProtectedRoute>
          }
        />
        {/* Settings: account only. Profile, change password,
            sign out other devices. */}
        <Route
          path="/admin/settings/:section?"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <SettingsPage />
            </ProtectedRoute>
          }
        />

        {/* -- HR Module -- */}
        <Route
          path="/hr/dashboard"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <HRDashboard />
            </ProtectedRoute>
          }
        />
        {/* Face enrollment moved to the Face action in HRIS > Employees */}
        <Route path="/hr/face-enrollment" element={<Navigate to="/hr/employees" replace />} />
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
        {/* Leave Requests: employees file their own from /my-leave, HR can
            also encode one, then approves or rejects it here. */}
        <Route
          path="/hr/leave-requests"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <LeaveRequestsPage />
            </ProtectedRoute>
          }
        />
        {/* Memos: disciplinary memos. Missed Time Outs become drafts HR
            reviews; HR issues, sends, and archives signed copies here. */}
        <Route
          path="/hr/memos"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <MemoManager />
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
        {/* the Kiosk PIN page moved into Rates and rules > Kiosk (owner, 2026-11-12) */}
        <Route path="/hr/kiosk-pin" element={<Navigate to="/hr/rates/kiosk" replace />} />
        {/* Rates and rules: moved from Admin > Settings, HR only */}
        <Route
          path="/hr/rates/:section?"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <RatesRulesPage />
            </ProtectedRoute>
          }
        />
        {/* Timesheets: HR approves each pay period's attendance, which makes the
            payroll (no pay amounts shown). HR then pays it on /hr/payroll. */}
        <Route
          path="/hr/timesheets"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <TimesheetsPage />
            </ProtectedRoute>
          }
        />
        {/* Payroll Records: pay, earnings, mark paid, payslips, 13th month */}
        <Route
          path="/hr/payroll"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <PayrollRecordsPage />
            </ProtectedRoute>
          }
        />
        {/* Holiday calendar: attendance, leave and payroll read holidays from it */}
        <Route
          path="/hr/holidays"
          element={
            <ProtectedRoute allowedRoles={["hr"]}>
              <HolidaysPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin/holidays"
          element={
            <ProtectedRoute allowedRoles={["admin"]}>
              <HolidaysPage admin />
            </ProtectedRoute>
          }
        />

        {/* -- Driver Module (night mode through NightScope) -- */}
        <Route
          path="/driver/deliveries"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <MobileAppShell><NightScope><DriverDeliveriesPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/driver/gps"
          element={
            <ProtectedRoute allowedRoles={["driver"]}>
              <MobileAppShell><NightScope><GpsTrackingPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />

        {/* -- Employee Module (Phase 13, night mode through NightScope) -- */}
        <Route
          path="/checker/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <MobileAppShell><NightScope><CheckerDashboard /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        {/* The Checker's Pickup Log was removed (owner, 2026-10-16). */}
        <Route path="/checker/pickup-log" element={<Navigate to="/checker/dashboard" replace />} />
        <Route
          path="/checker/ice-loading"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <MobileAppShell><NightScope><CheckerIceLoadingPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/inventory"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <MobileAppShell><NightScope><CheckerInventoryManagementPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/checker/stock-movements"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <MobileAppShell><NightScope><CheckerStockMovementsPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        {/* No stock-out release page for the Checker: the Admin releases
            Maintenance requests. (/checker/low-stock stays removed: Low
            Stock Alert is a tab in Inventory Management.) */}
        <Route
          path="/checker/suppliers"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["checker"]}>
              <MobileAppShell><NightScope><CheckerSupplierManagementPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/salesman/deliveries"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <MobileAppShell><NightScope><SalesmanDashboard /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/salesman/deliveries/:id/confirm"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["salesman"]}>
              <MobileAppShell><NightScope><SalesmanDeliveryConfirmation /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/maintenance/dashboard"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <MobileAppShell><NightScope><MaintenanceDashboard /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/maintenance/stock-out-request"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <MobileAppShell><NightScope><MaintenanceStockOutRequestPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />

        <Route
          path="/maintenance/projects"
          element={
            <ProtectedRoute allowedRoles={["employee"]} allowedSubRoles={["maintenance"]}>
              <MobileAppShell><NightScope><MaintenanceProjectsPage /></NightScope></MobileAppShell>
            </ProtectedRoute>
          }
        />

        {/* Any unknown URL goes to the login page instead of a blank screen */}
        <Route path="*" element={<Navigate to="/login" replace />} />

      </Routes>
      </Suspense>
    </BrowserRouter>
  )
}

export default App