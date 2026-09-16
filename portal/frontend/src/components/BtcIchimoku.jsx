import { useState } from 'react';
import Button from '@mui/material/Button';
import Collapse from '@mui/material/Collapse';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import apiClient from '../api/client';

const SIGNAL_LABELS = {
  cloud_green: 'Cloud is green',
  price_above_cloud: 'Price above cloud',
  conversion_cross: 'Conversion/baseline cross',
  chiku_above_cloud: 'Chiku span above cloud',
};

const PUMP_COLOR = { strong: 'success', moderate: 'warning', weak: 'warning', none: 'default' };

export default function BtcIchimoku() {
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState('idle');
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const toggle = () => {
    const next = !open;
    setOpen(next);
    if (next && status === 'idle') {
      setStatus('loading');
      apiClient
        .get('/market/btc-ichimoku')
        .then((response) => {
          setData(response.data);
          setStatus('ready');
        })
        .catch((err) => {
          setError(err.response?.data?.error || 'Could not load BTC analysis.');
          setStatus('error');
        });
    }
  };

  return (
    <Box sx={{ mb: 3 }}>
      <Button variant="outlined" onClick={toggle}>
        {open ? 'Hide' : 'Show'} BTC Ichimoku Analysis
      </Button>
      <Collapse in={open}>
        <Box sx={{ mt: 2 }}>
          {status === 'loading' && <CircularProgress size={24} aria-label="Loading BTC analysis" />}
          {status === 'error' && <Alert severity="error">{error}</Alert>}
          {status === 'ready' && data && (
            <>
              <Typography variant="h6">Current BTC/USDT Price: ${Number(data.price).toLocaleString()}</Typography>
              <Stack direction="row" spacing={1} flexWrap="wrap" sx={{ my: 1 }}>
                {Object.entries(data.signals).map(([key, value]) => (
                  <Chip
                    key={key}
                    label={SIGNAL_LABELS[key] || key}
                    color={value ? 'success' : 'default'}
                    variant={value ? 'filled' : 'outlined'}
                  />
                ))}
              </Stack>
              <Typography>
                Pump signal: <Chip size="small" label={data.pump_signal} color={PUMP_COLOR[data.pump_signal]} />
              </Typography>
              <Typography sx={{ mt: 1 }}>
                {data.sell_signal_triggered ? '🔴 Sell signal triggered' : '✅ No sell signal'}
              </Typography>
            </>
          )}
        </Box>
      </Collapse>
    </Box>
  );
}
