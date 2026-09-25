import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import ThemeProvider from '@mui/material/styles/ThemeProvider';
import CssBaseline from '@mui/material/CssBaseline';
import { useLocation } from 'react-router-dom';
import apiClient from '../api/client';
import createAppTheme from '../theme';
import { useAuth } from './AuthContext';

const ColorModeContext = createContext(null);
const AUTHENTICATED_PORTAL_PATHS = new Set([
  '/portal',
  '/properties',
  '/technical-analysis',
  '/profile',
  '/admin/users',
]);

export function ColorModeProvider({ children }) {
  const { user } = useAuth();
  const { pathname } = useLocation();
  const userId = user?.id;
  const [preference, setPreference] = useState('light');
  const [isLoadingPreference, setIsLoadingPreference] = useState(Boolean(userId));
  const [isSavingPreference, setIsSavingPreference] = useState(false);
  const [saveError, setSaveError] = useState(false);

  useEffect(() => {
    if (!userId) {
      setPreference('light');
      setIsLoadingPreference(false);
      return undefined;
    }

    let active = true;
    setIsLoadingPreference(true);
    apiClient.get('/preferences')
      .then((response) => {
        if (active) {
          setPreference(response.data.color_mode === 'dark' ? 'dark' : 'light');
        }
      })
      .catch(() => {
        if (active) setPreference('light');
      })
      .finally(() => {
        if (active) setIsLoadingPreference(false);
      });

    return () => {
      active = false;
    };
  }, [userId]);

  const isAuthenticatedPortalPage = Boolean(user) && (
    AUTHENTICATED_PORTAL_PATHS.has(pathname)
    || pathname === '/budget'
    || pathname.startsWith('/budget/')
  );
  const mode = isAuthenticatedPortalPage ? preference : 'light';
  const theme = useMemo(() => createAppTheme(mode), [mode]);

  const toggleColorMode = async () => {
    if (!userId || isLoadingPreference || isSavingPreference) return;

    const previousMode = preference;
    const nextMode = previousMode === 'dark' ? 'light' : 'dark';
    setPreference(nextMode);
    setIsSavingPreference(true);
    setSaveError(false);
    try {
      await apiClient.put('/preferences', { color_mode: nextMode });
    } catch {
      setPreference(previousMode);
      setSaveError(true);
    } finally {
      setIsSavingPreference(false);
    }
  };

  return (
    <ColorModeContext.Provider value={{ mode, toggleColorMode, isLoadingPreference, isSavingPreference, saveError }}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        {children}
      </ThemeProvider>
    </ColorModeContext.Provider>
  );
}

export function useColorMode() {
  return useContext(ColorModeContext);
}
