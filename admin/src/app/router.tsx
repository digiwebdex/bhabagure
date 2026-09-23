import { lazy, Suspense, type ComponentType } from 'react'
import { createBrowserRouter } from 'react-router'

import { Loading } from '../components/ui/layout'
import { LoginPage } from '../features/auth/LoginPage'
import { NotFound, Require } from './guards'
import { Shell } from './Shell'

/** Each screen is its own chunk: signing in doesn't download the rich text editor. */
const page = (load: () => Promise<Record<string, unknown>>, name: string) => {
  const Component = lazy(() => load().then((module) => ({ default: module[name] as ComponentType })))
  return function LazyPage() {
    return (
      <Suspense fallback={<Loading />}>
        <Component />
      </Suspense>
    )
  }
}

const AirTicketingPage = page(() => import('../features/air/AirTicketingPage'), 'AirTicketingPage')
const HotelRequestsPage = page(() => import('../features/hotel/HotelRequestsPage'), 'HotelRequestsPage')
const AttendancePage = page(() => import('../features/attendance/AttendancePage'), 'AttendancePage')
const AttendanceStaffPage = page(() => import('../features/attendance/AttendanceStaffPage'), 'AttendanceStaffPage')
const MyAttendancePage = page(() => import('../features/attendance/MyAttendancePage'), 'MyAttendancePage')
const PayrollPage = page(() => import('../features/payroll/PayrollPage'), 'PayrollPage')
const MyCommissionPage = page(() => import('../features/bonus/MyCommissionPage'), 'MyCommissionPage')
const BookingDetailPage = page(() => import('../features/bookings/BookingDetailPage'), 'BookingDetailPage')
const BookingListPage = page(() => import('../features/bookings/BookingListPage'), 'BookingListPage')
const NewBookingPage = page(() => import('../features/bookings/NewBookingPage'), 'NewBookingPage')
const ChangePasswordPage = page(() => import('../features/auth/ChangePasswordPage'), 'ChangePasswordPage')
const CustomerProfilePage = page(() => import('../features/customers/CustomerProfilePage'), 'CustomerProfilePage')
const CustomersPage = page(() => import('../features/customers/CustomersPage'), 'CustomersPage')
const DashboardPage = page(() => import('../features/dashboard/DashboardPage'), 'DashboardPage')
const DocumentsPage = page(() => import('../features/documents/DocumentsPage'), 'DocumentsPage')
const DownloadsPage = page(() => import('../features/downloads/DownloadsPage'), 'DownloadsPage')
const InvoicesPage = page(() => import('../features/invoices/InvoicesPage'), 'InvoicesPage')
const InvoiceFormPage = page(() => import('../features/invoices/InvoiceForm'), 'InvoiceForm')
const CustomerAccountsPage = page(() => import('../features/customer-accounts/CustomerAccountsPage'), 'CustomerAccountsPage')
const CouponsPage = page(() => import('../features/coupons/CouponsPage'), 'CouponsPage')
const CouponReportPage = page(() => import('../features/coupons/CouponReportPage'), 'CouponReportPage')
const ChartOfAccountsPage = page(() => import('../features/accounts/ChartOfAccountsPage'), 'ChartOfAccountsPage')
const JournalPage = page(() => import('../features/accounts/JournalPage'), 'JournalPage')
const AccountTransactionsPage = page(() => import('../features/accounts/ReportsPage'), 'AccountTransactionsPage')
const GeneralLedgerPage = page(() => import('../features/accounts/ReportsPage'), 'GeneralLedgerPage')
const GalleryPage = page(() => import('../features/cms/gallery/GalleryPage'), 'GalleryPage')
const CreatorPage = page(() => import('../features/cms/creator/CreatorPage'), 'CreatorPage')
const AirlinePartnersPage = page(() => import('../features/cms/partners/AirlinePartnersPage'), 'AirlinePartnersPage')
const OfferBannersPage = page(() => import('../features/cms/offers/OfferBannersPage'), 'OfferBannersPage')
const VisaServicesPage = page(() => import('../features/cms/visas/VisaServicesPage'), 'VisaServicesPage')
const MediaLibraryPage = page(() => import('../features/cms/media/MediaLibraryPage'), 'MediaLibraryPage')
const NotificationsPage = page(() => import('../features/notifications/NotificationsPage'), 'NotificationsPage')
const PackageEditorPage = page(() => import('../features/cms/packages/PackageEditorPage'), 'PackageEditorPage')
const PackageListPage = page(() => import('../features/cms/packages/PackageListPage'), 'PackageListPage')
const PostEditorPage = page(() => import('../features/cms/blog/PostEditorPage'), 'PostEditorPage')
const PostListPage = page(() => import('../features/cms/blog/PostListPage'), 'PostListPage')
const TransactionsPage = page(() => import('../features/transactions/TransactionsPage'), 'TransactionsPage')
const PricingPage = page(() => import('../features/cms/pricing/PricingPage'), 'PricingPage')
const QuotationDetailPage = page(() => import('../features/quotations/QuotationDetailPage'), 'QuotationDetailPage')
const QuotationsPage = page(() => import('../features/quotations/QuotationsPage'), 'QuotationsPage')
const ProfilePage = page(() => import('../features/profile/ProfilePage'), 'ProfilePage')
const ReviewsPage = page(() => import('../features/cms/reviews/ReviewsPage'), 'ReviewsPage')
const RolesPage = page(() => import('../features/staff/RolesPage'), 'RolesPage')
const SetPasswordPage = page(() => import('../features/auth/SetPasswordPage'), 'SetPasswordPage')
const SettingsPage = page(() => import('../features/cms/settings/SettingsPage'), 'SettingsPage')
const StaffPage = page(() => import('../features/staff/StaffPage'), 'StaffPage')
const StaffProfilePage = page(() => import('../features/staff/StaffProfilePage'), 'StaffProfilePage')
const VaultPage = page(() => import('../features/staff/VaultPage'), 'VaultPage')
const SupportPage = page(() => import('../features/support/SupportPage'), 'SupportPage')
const SupportTicketPage = page(() => import('../features/support/SupportTicketPage'), 'SupportTicketPage')
const TeamPage = page(() => import('../features/cms/team/TeamPage'), 'TeamPage')

const packages = ['packages.manage']
const cms = ['cms.manage']
const bookings = ['bookings.view_all', 'bookings.view_own']
const quotations = ['quotations.view_all', 'quotations.view_own']
const coupons = ['coupons.view', 'coupons.manage']

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  // Invitation and password-reset links (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): the token is the credential.
  { path: '/accept-invite', element: <SetPasswordPage /> },
  { path: '/reset-password', element: <SetPasswordPage /> },
  {
    path: '/change-password',
    element: (
      <Require>
        <ChangePasswordPage />
      </Require>
    ),
  },
  {
    element: (
      <Require>
        <Shell />
      </Require>
    ),
    children: [
      // The Dashboard for everyone; the API leaves out the widgets a role may not see (§4.1).
      { index: true, element: <DashboardPage /> },
      { path: 'bookings', element: <Require permissions={bookings}><BookingListPage /></Require> },
      { path: 'bookings/new', element: <Require permissions={['bookings.create']}><NewBookingPage /></Require> },
      { path: 'bookings/:id', element: <Require permissions={bookings}><BookingDetailPage /></Require> },
      { path: 'quotations', element: <Require permissions={quotations}><QuotationsPage /></Require> },
      { path: 'quotations/:id', element: <Require permissions={quotations}><QuotationDetailPage /></Require> },
      { path: 'customers', element: <Require permissions={['customers.view']}><CustomersPage /></Require> },
      { path: 'customers/:id', element: <Require permissions={['customers.view']}><CustomerProfilePage /></Require> },
      { path: 'downloads', element: <Require permissions={['downloads.view']}><DownloadsPage /></Require> },
      { path: 'invoices', element: <Require permissions={['payments.view']}><InvoicesPage /></Require> },
      { path: 'invoices/new', element: <Require permissions={['invoices.manage']}><InvoiceFormPage /></Require> },
      { path: 'invoices/:id/edit', element: <Require permissions={['invoices.manage']}><InvoiceFormPage /></Require> },
      { path: 'accounting/customers', element: <Require permissions={['payments.view']}><CustomerAccountsPage /></Require> },
      { path: 'accounts', element: <Require permissions={['accounts.view']}><ChartOfAccountsPage /></Require> },
      { path: 'journal', element: <Require permissions={['accounts.view']}><JournalPage /></Require> },
      { path: 'reports/account-transactions', element: <Require permissions={['accounts.view']}><AccountTransactionsPage /></Require> },
      { path: 'reports/general-ledger', element: <Require permissions={['accounts.view']}><GeneralLedgerPage /></Require> },
      { path: 'air-ticketing', element: <Require permissions={['air_inquiries.view']}><AirTicketingPage /></Require> },
      { path: 'hotel-requests', element: <Require permissions={['hotel_inquiries.view']}><HotelRequestsPage /></Require> },
      { path: 'documents', element: <Require permissions={bookings}><DocumentsPage /></Require> },
      { path: 'support', element: <Require permissions={['support.manage']}><SupportPage /></Require> },
      { path: 'support/:id', element: <Require permissions={['support.manage']}><SupportTicketPage /></Require> },
      { path: 'transactions', element: <Require permissions={['payments.view']}><TransactionsPage /></Require> },
      { path: 'notifications', element: <Require permissions={['notifications.manage']}><NotificationsPage /></Require> },
      { path: 'profile', element: <ProfilePage /> },
      { path: 'packages', element: <Require permissions={packages}><PackageListPage /></Require> },
      { path: 'packages/new', element: <Require permissions={packages}><PackageEditorPage /></Require> },
      { path: 'packages/:id', element: <Require permissions={packages}><PackageEditorPage /></Require> },
      { path: 'pricing', element: <Require permissions={['pricing.manage']}><PricingPage /></Require> },
      { path: 'coupons', element: <Require permissions={coupons}><CouponsPage /></Require> },
      { path: 'coupons/report', element: <Require permissions={coupons}><CouponReportPage /></Require> },
      { path: 'posts', element: <Require permissions={cms}><PostListPage /></Require> },
      { path: 'posts/new', element: <Require permissions={cms}><PostEditorPage /></Require> },
      { path: 'posts/:id', element: <Require permissions={cms}><PostEditorPage /></Require> },
      { path: 'team', element: <Require permissions={cms}><TeamPage /></Require> },
      { path: 'reviews', element: <Require permissions={cms}><ReviewsPage /></Require> },
      { path: 'gallery', element: <Require permissions={cms}><GalleryPage /></Require> },
      { path: 'travel-host', element: <Require permissions={cms}><CreatorPage /></Require> },
      { path: 'airline-partners', element: <Require permissions={cms}><AirlinePartnersPage /></Require> },
      { path: 'offer-banners', element: <Require permissions={cms}><OfferBannersPage /></Require> },
      { path: 'visas', element: <Require permissions={cms}><VisaServicesPage /></Require> },
      { path: 'media', element: <Require permissions={['packages.manage', 'cms.manage']}><MediaLibraryPage /></Require> },
      { path: 'settings', element: <Require permissions={cms}><SettingsPage /></Require> },
      { path: 'attendance', element: <Require permissions={['attendance.view_all', 'attendance.manage']}><AttendancePage /></Require> },
      { path: 'attendance/staff/:id', element: <Require permissions={['attendance.view_all', 'attendance.manage']}><AttendanceStaffPage /></Require> },
      { path: 'my-attendance', element: <MyAttendancePage /> },
      { path: 'payroll', element: <Require permissions={['payroll.view', 'payroll.manage']}><PayrollPage /></Require> },
      { path: 'my-commission', element: <Require permissions={['commission.view_own', 'commission.view_all']}><MyCommissionPage /></Require> },
      { path: 'staff', element: <Require permissions={['staff.manage']}><StaffPage /></Require> },
      { path: 'staff/:id', element: <Require permissions={['staff.manage']}><StaffProfilePage /></Require> },
      { path: 'vault', element: <Require permissions={['staff_documents.view']}><VaultPage /></Require> },
      { path: 'roles', element: <Require permissions={['system.roles_manage']}><RolesPage /></Require> },
      { path: '*', element: <NotFound /> },
    ],
  },
])
