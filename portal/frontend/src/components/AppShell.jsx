import Container from '@mui/material/Container';
import HeroHeader from './HeroHeader';
import NavBar from './NavBar';
import { useLocation } from 'react-router-dom';

export default function AppShell({ children }) {
  const { pathname } = useLocation();

  if (pathname === '/') {
    return children;
  }

  return (
    <>
      <HeroHeader />
      <NavBar />
      <Container component="main" className="py-4">
        {children}
      </Container>
    </>
  );
}
