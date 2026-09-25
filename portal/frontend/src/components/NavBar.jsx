import { useEffect, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import Tabs from '@mui/material/Tabs';
import Tab from '@mui/material/Tab';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import MenuRoundedIcon from '@mui/icons-material/MenuRounded';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { navigationFor } from '../navigation';

// Below 1000px the nav collapses into a drawer before the labels can wrap.
const NAV_DESKTOP_QUERY = '(min-width: 1000px)';
const NAV_DESKTOP_MEDIA = '@media (min-width: 1000px)';

export default function NavBar() {
  const [accountMenuAnchor, setAccountMenuAnchor] = useState(null);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { user, status, logout } = useAuth();
  const links = navigationFor(user);
  const headerLinks = [...links.filter((link) => link.label !== 'Users'), ...links.filter((link) => link.label === 'Users')];
  const drawerLinks = [...links.filter((link) => link.label !== 'Users'), ...links.filter((link) => link.label === 'Users')];
  const activeLink = links.find((link) => link.to === pathname || (link.to === '/budget' && pathname.startsWith('/budget/')));
  const currentValue = activeLink?.to || false;
  const isTabletOrWider = useMediaQuery(NAV_DESKTOP_QUERY);

  useEffect(() => {
    if (isTabletOrWider) {
      setMobileNavOpen(false);
    }
  }, [isTabletOrWider]);

  const handleNavClick = (to) => {
    setMobileNavOpen(false);
    navigate(to);
  };

  return (
    <Box
      component="header"
      sx={{
        display: 'flex',
        flexWrap: 'wrap',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: { xs: 0.75, sm: 1.5 },
        width: '100%',
        px: { xs: 1, sm: 2, md: 3 },
        py: { xs: 1, sm: 0.75 },
        bgcolor: 'background.paper',
        borderBottom: '1px solid',
        borderColor: 'divider',
      }}
    >
      <Box sx={{ display: 'flex', [NAV_DESKTOP_MEDIA]: { display: 'none' }, alignItems: 'center' }}>
        <Button
          aria-label={mobileNavOpen ? 'Close navigation menu' : 'Open navigation menu'}
          aria-controls="main-nav-drawer"
          aria-expanded={mobileNavOpen}
          aria-haspopup="dialog"
          color="inherit"
          startIcon={<MenuRoundedIcon />}
          onClick={() => setMobileNavOpen((open) => !open)}
          size="small"
          sx={{
            display: 'inline-flex',
            [NAV_DESKTOP_MEDIA]: { display: 'none' },
            border: '1px solid',
            borderColor: 'divider',
            borderRadius: 2,
            minHeight: 40,
            px: 1.5,
            textTransform: 'none',
          }}
        >
          Menu
        </Button>
      </Box>

      <Box component="nav" aria-label="MainzWare navigation" sx={{ display: 'none', [NAV_DESKTOP_MEDIA]: { display: 'flex' }, flex: '1 1 auto', minWidth: 0, alignItems: 'center', justifyContent: 'center' }}>
        <Tabs value={currentValue} variant="standard" aria-label="Mainz World navigation" sx={{ minHeight: 48, whiteSpace: 'nowrap', '& .MuiTabs-flexContainer': { flexWrap: 'nowrap' }, '& .MuiTabs-scroller': { overflow: 'hidden' } }}>
          {headerLinks.filter((link) => link.label !== 'Users').map((link) => (
            <Tab key={link.to} label={link.label} value={link.to} component={NavLink} to={link.to} />
          ))}
          <Tab label="Live Worship" value="/live-worship" component="a" href="/live-worship/" />
          {headerLinks.filter((link) => link.label === 'Users').map((link) => (
            <Tab key={link.to} label={link.label} value={link.to} component={NavLink} to={link.to} />
          ))}
        </Tabs>
      </Box>

      <Drawer
        anchor="left"
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        ModalProps={{ keepMounted: true }}
        PaperProps={{
          sx: {
            width: 'min(86vw, 320px)',
            borderTopRightRadius: 12,
            borderBottomRightRadius: 12,
          },
        }}
      >
        <Box id="main-nav-drawer" role="dialog" aria-label="Main navigation" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
            <Typography component={NavLink} to="/" variant="subtitle1" onClick={() => setMobileNavOpen(false)} sx={{ fontWeight: 700, color: 'inherit', textDecoration: 'none' }}>
              MainzWare
            </Typography>
            <IconButton aria-label="Close navigation menu" onClick={() => setMobileNavOpen(false)} size="small">
              <CloseRoundedIcon fontSize="small" />
            </IconButton>
          </Stack>
          <List disablePadding sx={{ display: 'grid', gap: 0.5 }}>
            {drawerLinks.map((link) => (
              <ListItemButton
                key={link.to}
                selected={pathname === link.to}
                onClick={() => handleNavClick(link.to)}
                sx={{
                  borderRadius: 2,
                  minHeight: 44,
                  order: link.label === 'Users' ? 2 : 1,
                  '&.Mui-selected': {
                    bgcolor: 'primary.main',
                    color: 'primary.contrastText',
                  },
                  '&.Mui-selected:hover': {
                    bgcolor: 'primary.dark',
                  },
                }}
              >
                <ListItemText primary={link.label} primaryTypographyProps={{ fontWeight: pathname === link.to ? 700 : 500 }} />
              </ListItemButton>
            ))}
            <ListItemButton component="a" href="/live-worship/" onClick={() => setMobileNavOpen(false)} sx={{ borderRadius: 2, minHeight: 44, order: 1 }}>
              <ListItemText primary="Live Worship" />
            </ListItemButton>
          </List>
        </Box>
      </Drawer>

      {status === 'ready' && (
        <Box sx={{ flex: '1 1 auto', display: 'flex', justifyContent: 'flex-end', alignItems: 'center', flexWrap: 'nowrap', gap: 1, minWidth: 0, px: { xs: 0, md: 1 }, pt: 0, borderTop: 'none', borderColor: 'divider', [NAV_DESKTOP_MEDIA]: { flex: '0 0 100%', justifyContent: 'space-between', pt: 0.5, borderTop: '1px solid' } }}>
          <Button component={NavLink} to="/" size="small" sx={{ textTransform: 'none' }}>Homepage</Button>
          {user ? (
            <>
              <Button
                aria-controls={accountMenuAnchor ? 'account-menu' : undefined}
                aria-expanded={Boolean(accountMenuAnchor)}
                aria-haspopup="menu"
                endIcon={<ExpandMoreIcon />}
                onClick={(event) => setAccountMenuAnchor(event.currentTarget)}
                size="small"
              >
                <Box component="span" sx={{ maxWidth: { xs: 130, sm: 220 }, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user.username}</Box>
              </Button>
              <Menu
                anchorEl={accountMenuAnchor}
                id="account-menu"
                onClose={() => setAccountMenuAnchor(null)}
                open={Boolean(accountMenuAnchor)}
              >
                <MenuItem component={NavLink} onClick={() => setAccountMenuAnchor(null)} to="/profile">Preferences</MenuItem>
                <MenuItem onClick={async () => {
                  setAccountMenuAnchor(null);
                  await logout();
                  navigate('/login');
                }}>
                  Log Out
                </MenuItem>
              </Menu>
            </>
          ) : (
            <Button size="small" component={NavLink} to="/login">Log In</Button>
          )}
        </Box>
      )}
    </Box>
  );
}
