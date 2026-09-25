import { Navigate, useLocation } from 'react-router-dom';
import { useEffect } from 'react';
import Typography from '@mui/material/Typography';
import LoginForm from '../components/LoginForm';
import { useAuth } from '../context/AuthContext';

export default function Login() {
  const { user, status } = useAuth();
  const location = useLocation();
  const requestedReturn = new URLSearchParams(location.search).get('return');
  const safeReturn = requestedReturn?.startsWith('/') && !requestedReturn.startsWith('//') ? requestedReturn : null;
  const from = location.state?.from || safeReturn || '/portal';
  const isLiveWorshipReturn = from === '/live-worship' || from.startsWith('/live-worship/');

  useEffect(() => {
    if (user && isLiveWorshipReturn) window.location.assign(from);
  }, [from, isLiveWorshipReturn, user]);

  if (status === 'loading') return null;
  if (user && isLiveWorshipReturn) return null;
  if (user) return <Navigate to={from} replace />;

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        Log In
      </Typography>
      <LoginForm />
    </>
  );
}
