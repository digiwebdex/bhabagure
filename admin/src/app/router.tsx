import { lazy, Suspense, type ComponentType } from 'react'
import { createBrowserRouter } from 'react-router'

import { Loading } from '../components/ui/layout'
import { LoginPage } from '../features/auth/LoginPage'
import { Home, NotFound, Require } from './guards'
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

const BookingDetailPage = page(() => import('../features/bookings/BookingDetailPage'), 'BookingDetailPage')
const BookingListPage = page(() => import('../features/bookings/BookingListPage'), 'BookingListPage')
const NewBookingPage = page(() => import('../features/bookings/NewBookingPage'), 'NewBookingPage')
const ChangePasswordPage = page(() => import('../features/auth/ChangePasswordPage'), 'ChangePasswordPage')
const CustomerProfilePage = page(() => import('../features/customers/CustomerProfilePage'), 'CustomerProfilePage')
const CustomersPage = page(() => import('../features/customers/CustomersPage'), 'CustomersPage')
const GalleryPage = page(() => import('../features/cms/gallery/GalleryPage'), 'GalleryPage')
const MediaLibraryPage = page(() => import('../features/cms/media/MediaLibraryPage'), 'MediaLibraryPage')
const NotificationsPage = page(() => import('../features/notifications/NotificationsPage'), 'NotificationsPage')
const PackageEditorPage = page(() => import('../features/cms/packages/PackageEditorPage'), 'PackageEditorPage')
const PackageListPage = page(() => import('../features/cms/packages/PackageListPage'), 'PackageListPage')
const PostEditorPage = page(() => import('../features/cms/blog/PostEditorPage'), 'PostEditorPage')
const PostListPage = page(() => import('../features/cms/blog/PostListPage'), 'PostListPage')
const PricingPage = page(() => import('../features/cms/pricing/PricingPage'), 'PricingPage')
const ProfilePage = page(() => import('../features/profile/ProfilePage'), 'ProfilePage')
const ReviewsPage = page(() => import('../features/cms/reviews/ReviewsPage'), 'ReviewsPage')
const SettingsPage = page(() => import('../features/cms/settings/SettingsPage'), 'SettingsPage')
const TeamPage = page(() => import('../features/cms/team/TeamPage'), 'TeamPage')

const packages = ['packages.manage']
const cms = ['cms.manage']
const bookings = ['bookings.view_all', 'bookings.view_own']

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
      { index: true, element: <Home /> },
      { path: 'bookings', element: <Require permissions={bookings}><BookingListPage /></Require> },
      { path: 'bookings/new', element: <Require permissions={['bookings.create']}><NewBookingPage /></Require> },
      { path: 'bookings/:id', element: <Require permissions={bookings}><BookingDetailPage /></Require> },
      { path: 'customers', element: <Require permissions={['customers.view']}><CustomersPage /></Require> },
      { path: 'customers/:id', element: <Require permissions={['customers.view']}><CustomerProfilePage /></Require> },
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
