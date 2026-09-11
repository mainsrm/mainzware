import { Navigate, useLocation } from 'react-router-dom';
import Typography from '@mui/material/Typography';
import LoginForm from '../components/LoginForm';
import { useAuth } from '../context/AuthContext';

export default function Login() {
  const { user, status } = useAuth();
  const location = useLocation();
  const from = location.state?.from || '/what-da-money';

  if (status === 'loading') return null;
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
