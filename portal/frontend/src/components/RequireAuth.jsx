import { Navigate, useLocation } from 'react-router-dom';
import CircularProgress from '@mui/material/CircularProgress';
import Alert from '@mui/material/Alert';
import { useAuth } from '../context/AuthContext';

// Wrap a route element to require login (and optionally the admin role).
export default function RequireAuth({ children, adminOnly = false }) {
  const { user, status } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <CircularProgress aria-label="Checking login status" />;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  if (adminOnly && user.role !== 'admin') {
    return <Alert severity="error">You must be an admin to view this page.</Alert>;
  }

  return children;
}
