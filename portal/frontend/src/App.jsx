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
import TechnicalAnalysis from './pages/TechnicalAnalysis';
import BudgetApp from './pages/BudgetApp';
import RequireAuth from './components/RequireAuth';

export default function App() {
  return (
    <AppShell>
      <ErrorBoundary>
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/portal" element={<RequireAuth><Dashboard /></RequireAuth>} />
          {/* Old dashboard URL kept as a redirect so existing bookmarks/links keep working. */}
          <Route path="/mainz-world" element={<Navigate to="/portal" replace />} />
          <Route path="/properties" element={<RequireAuth><Properties /></RequireAuth>} />
          <Route path="/budget" element={<RequireAuth><BudgetApp /></RequireAuth>}>
            <Route index element={<Navigate to="what-da-money" replace />} />
            <Route path="what-da-money" element={<Budget />} />
            <Route path="where-da-money" element={<WhereDaMoney />} />
            <Route path="snowball" element={<Snowball />} />
          </Route>
          <Route path="/technical-analysis" element={<RequireAuth><TechnicalAnalysis /></RequireAuth>} />
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
  );
}
