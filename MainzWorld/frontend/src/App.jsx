import { Routes, Route } from 'react-router-dom';
import AppShell from './components/AppShell';
import Home from './pages/Home';
import Properties from './pages/Properties';
import Budget from './pages/Budget';
import WhereDaMoney from './pages/WhereDaMoney';
import Login from './pages/Login';
import Users from './pages/Users';
import Profile from './pages/Profile';
import RequireAuth from './components/RequireAuth';
import { AuthProvider } from './context/AuthContext';

export default function App() {
  return (
    <AuthProvider>
      <AppShell>
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/properties" element={<RequireAuth><Properties /></RequireAuth>} />
          <Route path="/what-da-money" element={<RequireAuth><Budget /></RequireAuth>} />
          <Route path="/where-da-money" element={<RequireAuth><WhereDaMoney /></RequireAuth>} />
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
      </AppShell>
    </AuthProvider>
  );
}
