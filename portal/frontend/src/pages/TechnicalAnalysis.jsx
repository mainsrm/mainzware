import Typography from '@mui/material/Typography';
import BtcIchimoku from '../components/BtcIchimoku';

export default function TechnicalAnalysis() {
  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>
        Stock Indicators
      </Typography>
      <Typography sx={{ mb: 3 }}>
        Review market signals and technical indicators across stocks and crypto markets.
      </Typography>
      <BtcIchimoku />
    </>
  );
}
