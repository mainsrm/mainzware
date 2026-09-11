import { useEffect, useState } from 'react';
import Tabs from '@mui/material/Tabs';
import Tab from '@mui/material/Tab';
import Grid from '@mui/material/Grid';
import Card from '@mui/material/Card';
import CardMedia from '@mui/material/CardMedia';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import Box from '@mui/material/Box';
import apiClient from '../api/client';
import { useAuth } from '../context/AuthContext';
import LoginForm from '../components/LoginForm';
import CmsAddContentForm from '../components/CmsAddContentForm';

const PAGES = ['Home', 'Page_01', 'Page_02', 'Page_03'];

export default function Cms() {
  const { user, status: authStatus } = useAuth();
  const [page, setPage] = useState('Home');
  const [content, setContent] = useState([]);
  const [status, setStatus] = useState('loading');

  const loadContent = () => {
    setStatus('loading');
    apiClient
      .get('/content', { params: { page } })
      .then((response) => {
        setContent(response.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(loadContent, [page]);

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        CMS
      </Typography>

      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, mb: 2 }}>
        <Tabs
          value={page}
          onChange={(_, value) => setPage(value)}
          aria-label="CMS pages"
          variant="scrollable"
          scrollButtons="auto"
          allowScrollButtonsMobile
        >
          {PAGES.map((p) => (
            <Tab key={p} label={p.replace('_', ' ')} value={p} />
          ))}
        </Tabs>
      </Box>

      {status === 'loading' && <CircularProgress aria-label="Loading content" />}
      {status === 'error' && <Alert severity="error">Could not load content.</Alert>}
      {status === 'ready' && content.length === 0 && (
        <Typography sx={{ mb: 3 }}>No content on this page yet.</Typography>
      )}
      {status === 'ready' && content.length > 0 && (
        <Grid container spacing={2} sx={{ mb: 3 }}>
          {content.map((item) => (
            <Grid item xs={12} sm={6} md={4} key={item.id}>
              <Card>
                {item.image_url && <CardMedia component="img" height="160" image={item.image_url} alt={item.title} />}
                <CardContent>
                  <Typography variant="h6" component="h3">{item.title}</Typography>
                  {item.location && (
                    <Typography variant="body2" color="text.secondary">{item.location}</Typography>
                  )}
                  {item.details && <Typography variant="body2" sx={{ mt: 1 }}>{item.details}</Typography>}
                </CardContent>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}

      {authStatus === 'ready' && (user ? (
        <CmsAddContentForm page={page} onAdded={loadContent} />
      ) : (
        <LoginForm />
      ))}
    </>
  );
}
