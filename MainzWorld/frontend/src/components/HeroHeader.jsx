import PublicIcon from '@mui/icons-material/Public';
import './HeroHeader.css';

// Original "retro bold text on a cloudy sky" banner style for the Mainz World hub.
export default function HeroHeader() {
  return (
    <header className="hero-header">
      <h1 className="hero-header__title">
        <span>MAINZ</span>
        <span className="hero-header__world">
          W<PublicIcon className="hero-header__globe" aria-hidden="true" fontSize="inherit" />RLD
        </span>
      </h1>
    </header>
  );
}
