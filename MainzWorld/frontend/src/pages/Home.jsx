import Grid from '@mui/material/Grid';
import CircularProgress from '@mui/material/CircularProgress';
import ProjectCard from '../components/ProjectCard';
import BtcIchimoku from '../components/BtcIchimoku';
import { useAuth } from '../context/AuthContext';
import Typography from '@mui/material/Typography';
import { navigationFor } from '../navigation';

export default function Home() {
  const { user, status: authStatus } = useAuth();

  if (authStatus === 'loading') {
    return <CircularProgress aria-label="Loading Mainz World" />;
  }

  if (authStatus === 'ready' && !user) {
    return (
      <>
        <Typography variant="h4" component="h2" gutterBottom>
          Welcome to Mainz World
        </Typography>
        <Typography sx={{ mb: 2 }}>
          Log in to access your projects, What Da Money, Where Da Money, property sales, and market analysis.
        </Typography>
      </>
    );
  }

  const projects = navigationFor(user).filter((item) => item.to !== '/').map((item) => ({
    name: item.label,
    description: item.description,
    url: item.to,
  }));

  return (
    <>
      <Grid container spacing={2} sx={{ mb: 3 }}>
        {projects.map((project) => (
          <Grid item xs={12} sm={6} md={4} key={project.name}>
            <ProjectCard project={project} />
          </Grid>
        ))}
      </Grid>

      <BtcIchimoku />
    </>
  );
}
