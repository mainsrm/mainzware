import { Routes, Route, Navigate } from 'react-router-dom';
import AppShell from './components/AppShell';
import ErrorBoundary from './components/ErrorBoundary';
import Home from './pages/Home';
import Dashboard from './pages/Dashboard';
import Properties from './pages/Properties';
import Budget from './pages/Budget';
import WhereDaMoney from './pages/WhereDaMoney';
import Snowball from './pages/Snowball';
import Login from './pages/Login';
import Users from './pages/Users';
import Profile from './pages/Profile';
import RequireAuth from './components/RequireAuth';
import { AuthProvider } from './context/AuthContext';

export default function App() {
  return (
    <AuthProvider>
      <AppShell>
        <ErrorBoundary>
          <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/portal" element={<RequireAuth><Dashboard /></RequireAuth>} />
            {/* Old dashboard URL kept as a redirect so existing bookmarks/links keep working. */}
            <Route path="/mainz-world" element={<Navigate to="/portal" replace />} />
            <Route path="/properties" element={<RequireAuth><Properties /></RequireAuth>} />
            <Route path="/what-da-money" element={<RequireAuth><Budget /></RequireAuth>} />
            <Route path="/where-da-money" element={<RequireAuth><WhereDaMoney /></RequireAuth>} />
            <Route path="/snowball" element={<RequireAuth><Snowball /></RequireAuth>} />
            <Route path="/login" element={<Login />} />
            <Route path="/profile" element={<RequireAuth><Profile /></RequireAuth>} />
            <Route
              path="/admin/users"
              element={
                <RequireAuth adminOnly>
                  <Users />
                </RequireAuth>
              }
            />
          </Routes>
        </ErrorBoundary>
      </AppShell>
    </AuthProvider>
  );
}
