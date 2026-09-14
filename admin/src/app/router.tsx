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
const BookingDetailPage = page(() => import('../features/bookings/BookingDetailPage'), 'BookingDetailPage')
const BookingListPage = page(() => import('../features/bookings/BookingListPage'), 'BookingListPage')
const NewBookingPage = page(() => import('../features/bookings/NewBookingPage'), 'NewBookingPage')
const ChangePasswordPage = page(() => import('../features/auth/ChangePasswordPage'), 'ChangePasswordPage')
const CustomerProfilePage = page(() => import('../features/customers/CustomerProfilePage'), 'CustomerProfilePage')
const CustomersPage = page(() => import('../features/customers/CustomersPage'), 'CustomersPage')
const DashboardPage = page(() => import('../features/dashboard/DashboardPage'), 'DashboardPage')
const GalleryPage = page(() => import('../features/cms/gallery/GalleryPage'), 'GalleryPage')
const MediaLibraryPage = page(() => import('../features/cms/media/MediaLibraryPage'), 'MediaLibraryPage')
const NotificationsPage = page(() => import('../features/notifications/NotificationsPage'), 'NotificationsPage')
const PackageEditorPage = page(() => import('../features/cms/packages/PackageEditorPage'), 'PackageEditorPage')
const PackageListPage = page(() => import('../features/cms/packages/PackageListPage'), 'PackageListPage')
const PostEditorPage = page(() => import('../features/cms/blog/PostEditorPage'), 'PostEditorPage')
const PostListPage = page(() => import('../features/cms/blog/PostListPage'), 'PostListPage')
const PaymentsPage = page(() => import('../features/payments/PaymentsPage'), 'PaymentsPage')
const PricingPage = page(() => import('../features/cms/pricing/PricingPage'), 'PricingPage')
const QuotationDetailPage = page(() => import('../features/quotations/QuotationDetailPage'), 'QuotationDetailPage')
const QuotationsPage = page(() => import('../features/quotations/QuotationsPage'), 'QuotationsPage')
const ProfilePage = page(() => import('../features/profile/ProfilePage'), 'ProfilePage')
const ReviewsPage = page(() => import('../features/cms/reviews/ReviewsPage'), 'ReviewsPage')
const SettingsPage = page(() => import('../features/cms/settings/SettingsPage'), 'SettingsPage')
const TeamPage = page(() => import('../features/cms/team/TeamPage'), 'TeamPage')

const packages = ['packages.manage']
const cms = ['cms.manage']
const bookings = ['bookings.view_all', 'bookings.view_own']
const quotations = ['quotations.view_all', 'quotations.view_own']

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
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
      { path: 'air-ticketing', element: <Require permissions={['air_inquiries.view']}><AirTicketingPage /></Require> },
      { path: 'payments', element: <Require permissions={['payments.view']}><PaymentsPage /></Require> },
      { path: 'notifications', element: <Require permissions={['notifications.manage']}><NotificationsPage /></Require> },
      { path: 'profile', element: <ProfilePage /> },
      { path: 'packages', element: <Require permissions={packages}><PackageListPage /></Require> },
      { path: 'packages/new', element: <Require permissions={packages}><PackageEditorPage /></Require> },
      { path: 'packages/:id', element: <Require permissions={packages}><PackageEditorPage /></Require> },
      { path: 'pricing', element: <Require permissions={['pricing.manage']}><PricingPage /></Require> },
      { path: 'posts', element: <Require permissions={cms}><PostListPage /></Require> },
      { path: 'posts/new', element: <Require permissions={cms}><PostEditorPage /></Require> },
      { path: 'posts/:id', element: <Require permissions={cms}><PostEditorPage /></Require> },
      { path: 'team', element: <Require permissions={cms}><TeamPage /></Require> },
      { path: 'reviews', element: <Require permissions={cms}><ReviewsPage /></Require> },
      { path: 'gallery', element: <Require permissions={cms}><GalleryPage /></Require> },
      { path: 'media', element: <Require permissions={['packages.manage', 'cms.manage']}><MediaLibraryPage /></Require> },
      { path: 'settings', element: <Require permissions={cms}><SettingsPage /></Require> },
      { path: '*', element: <NotFound /> },
    ],
  },
])
