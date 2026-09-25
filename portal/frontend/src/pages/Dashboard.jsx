import Grid from '@mui/material/Grid';
import Typography from '@mui/material/Typography';
import ProjectCard from '../components/ProjectCard';
import { navigationFor } from '../navigation';
import { useAuth } from '../context/AuthContext';

export default function Dashboard() {
  const { user } = useAuth();
  const projects = [
    ...navigationFor(user)
      .filter((item) => item.to !== '/portal' && item.to !== '/')
      .map((item) => ({ name: item.label, description: item.description, url: item.to })),
    {
      name: 'Live Worship',
      description: 'Open the independent worship song catalog and live song controls.',
      url: '/live-worship/',
      external: true,
    },
  ].sort((a, b) => Number(a.name === 'Users') - Number(b.name === 'Users'));

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        Portal
      </Typography>
      <Typography sx={{ mb: 3 }}>
        Your private workspace for budgets, property sales, and market analysis.
      </Typography>
      <Grid container spacing={2} sx={{ mb: 3 }}>
        {projects.map((project) => (
          <Grid item xs={12} sm={6} md={4} key={project.name}>
            <ProjectCard project={project} />
          </Grid>
        ))}
      </Grid>
    </>
  );
}
