import Container from '@mui/material/Container';
import HeroHeader from './HeroHeader';
import NavBar from './NavBar';

export default function AppShell({ children }) {
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
