import ArrowUpwardRoundedIcon from '@mui/icons-material/ArrowUpwardRounded';
import AutoAwesomeRoundedIcon from '@mui/icons-material/AutoAwesomeRounded';
import HubRoundedIcon from '@mui/icons-material/HubRounded';
import LanRoundedIcon from '@mui/icons-material/LanRounded';
import QueryStatsRoundedIcon from '@mui/icons-material/QueryStatsRounded';
import SettingsSuggestRoundedIcon from '@mui/icons-material/SettingsSuggestRounded';
import TerminalRoundedIcon from '@mui/icons-material/TerminalRounded';
import logo from '../../../../brand/logo square.png';
import './Home.css';

const services = [
  {
    icon: TerminalRoundedIcon,
    number: '01',
    title: 'Custom software',
    description: 'Purpose-built software and web applications designed around your processes, data, and goals.',
  },
  {
    icon: LanRoundedIcon,
    number: '02',
    title: 'Network & infrastructure',
    description: 'Network design, installation, configuration, troubleshooting, and infrastructure that holds up.',
  },
  {
    icon: QueryStatsRoundedIcon,
    number: '03',
    title: 'Computer & IT consulting',
    description: 'Practical guidance for choosing, implementing, maintaining, and improving the systems you depend on.',
  },
  {
    icon: SettingsSuggestRoundedIcon,
    number: '04',
    title: 'Business automation',
    description: 'Reduce repetitive work by connecting systems, automating processes, and putting your data to work.',
  },
  {
    icon: HubRoundedIcon,
    number: '05',
    title: 'System integration',
    description: 'Connect the software, databases, devices, and services your business already uses.',
  },
];

export default function Home() {
  return (
    <div className="mainzware-site">
      <header className="mw-header">
        <a className="mw-brand" href="#top" aria-label="MainzWare home">
          <img src={logo} alt="MainzWare" />
        </a>
        <nav className="mw-nav" aria-label="Main navigation">
          <a href="#services">Services</a>
          <a href="#approach">Our approach</a>
          <a className="mw-nav__portal" href="/login">Log in <ArrowUpwardRoundedIcon aria-hidden="true" /></a>
        </nav>
      </header>

      <main id="top">
        <section className="mw-hero" aria-labelledby="hero-title">
          <div className="mw-hero__copy">
            <p className="mw-kicker"><AutoAwesomeRoundedIcon aria-hidden="true" /> Technology, made useful</p>
            <h1 id="hero-title">Technology built <em>around</em> your business.</h1>
            <p className="mw-hero__lede">Custom software. Reliable networks. Practical technology solutions.</p>
            <p className="mw-hero__body">We build technology that works the way your business works, from custom applications and web tools to the infrastructure that keeps everything moving.</p>
            <a className="mw-button" href="#contact">Start a conversation <ArrowUpwardRoundedIcon aria-hidden="true" /></a>
          </div>
          <div className="mw-hero__visual" aria-label="MainzWare technology solutions" role="img">
            <div className="mw-hero__shine" />
            <img src={logo} alt="" className="mw-hero__logo" />
            <div className="mw-orbit mw-orbit--one" />
            <div className="mw-orbit mw-orbit--two" />
            <div className="mw-node mw-node--center"><span>MW</span><small>your business</small></div>
            <div className="mw-node mw-node--top"><TerminalRoundedIcon aria-hidden="true" /><small>software</small></div>
            <div className="mw-node mw-node--right"><LanRoundedIcon aria-hidden="true" /><small>network</small></div>
            <div className="mw-node mw-node--bottom"><QueryStatsRoundedIcon aria-hidden="true" /><small>insight</small></div>
            <div className="mw-node mw-node--left"><HubRoundedIcon aria-hidden="true" /><small>systems</small></div>
          </div>
        </section>

        <section className="mw-intro" id="approach" aria-labelledby="intro-title">
          <p className="mw-kicker">The MainzWare difference</p>
          <div className="mw-intro__grid">
            <h2 id="intro-title">Custom technology.<br /><em>Real solutions.</em></h2>
            <div>
              <p>Your business is unique. Your technology should be too.</p>
              <p>We learn how you operate, find where technology can make a difference, and build around those needs. No one-size-fits-all products. No unnecessary complexity.</p>
            </div>
          </div>
        </section>

        <section className="mw-services" id="services" aria-labelledby="services-title">
          <div className="mw-section-heading">
            <div><p className="mw-kicker">What we do</p><h2 id="services-title">The right tool<br /><em>for the job.</em></h2></div>
            <p>From a single workflow to your whole technology environment, we bring clarity to complicated problems.</p>
          </div>
          <div className="mw-service-grid">
            {services.map(({ icon: Icon, number, title, description }) => (
              <article className="mw-service" key={title}>
                <div className="mw-service__top"><span>{number}</span><Icon aria-hidden="true" /></div>
                <h3>{title}</h3>
                <p>{description}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="mw-cta" id="contact" aria-labelledby="cta-title">
          <div><p className="mw-kicker">No standard package required</p><h2 id="cta-title">Let’s build something<br /><em>that works.</em></h2></div>
          <div className="mw-cta__action"><p>Tell us what you’re trying to accomplish. We’ll help you find a practical technology solution.</p><a className="mw-button mw-button--light" href="mailto:hello@mainzware.com">Get started <ArrowUpwardRoundedIcon aria-hidden="true" /></a></div>
        </section>
      </main>

      <footer className="mw-footer"><span>© {new Date().getFullYear()} MainzWare</span><span>Technology with purpose.</span></footer>
    </div>
  );
}
