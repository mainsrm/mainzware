import ApartmentRoundedIcon from '@mui/icons-material/ApartmentRounded';
import ArrowUpwardRoundedIcon from '@mui/icons-material/ArrowUpwardRounded';
import AutoAwesomeRoundedIcon from '@mui/icons-material/AutoAwesomeRounded';
import CableRoundedIcon from '@mui/icons-material/CableRounded';
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded';
import HubRoundedIcon from '@mui/icons-material/HubRounded';
import LanguageRoundedIcon from '@mui/icons-material/LanguageRounded';
import RouterRoundedIcon from '@mui/icons-material/RouterRounded';
import SettingsSuggestRoundedIcon from '@mui/icons-material/SettingsSuggestRounded';
import TerminalRoundedIcon from '@mui/icons-material/TerminalRounded';
import VideocamRoundedIcon from '@mui/icons-material/VideocamRounded';
// Logo lockups: black wordmark for light surfaces, white wordmark for dark surfaces.
import logoOnLight from '../../../../brand/M Logo Black.png';
import logoOnDark from '../../../../brand/M logo white.png';
import './Home.css';

// Three co-equal disciplines, each backed by a short list of concrete capabilities.
const pillars = [
  {
    icon: TerminalRoundedIcon,
    number: '01',
    title: 'Software',
    items: ['Custom Software Development', 'Business & Personal Applications', 'Databases & Integrations', 'Reporting & Data Analytics'],
  },
  {
    icon: LanguageRoundedIcon,
    number: '02',
    title: 'Web',
    items: ['Website Design', 'Web Development', 'Responsive Experiences', 'Modern Web Applications'],
  },
  {
    icon: SettingsSuggestRoundedIcon,
    number: '03',
    title: 'Consulting',
    items: ['Technology Strategy', 'Architecture & Planning', 'System Design', 'Technology Problem Solving'],
  },
];

// Secondary, on-request hardware/infrastructure capabilities (not a headline pillar).
const beyondTags = [
  { icon: VideocamRoundedIcon, label: 'Cameras' },
  { icon: RouterRoundedIcon, label: 'Networks' },
  { icon: ApartmentRoundedIcon, label: 'Infrastructure' },
  { icon: CableRoundedIcon, label: 'Connectivity' },
];

const approachSteps = [
  { number: '01', title: 'Assess' },
  { number: '02', title: 'Design' },
  { number: '03', title: 'Implement' },
  { number: '04', title: 'Support' },
];

const commitmentItems = [
  'Reliable & Secure',
  'Scalable & Flexible',
  'Proactive Support',
  'Business-Focused Solutions',
  'A Partner You Can Trust',
];

export default function Home() {
  return (
    <div className="mainzware-site">
      <header className="mw-header">
        <a className="mw-brand" href="#top" aria-label="MainzWare home">
          <img src={logoOnLight} alt="MainzWare" />
        </a>
        <nav className="mw-nav" aria-label="Main navigation">
          <a href="#services">Services</a>
          <a href="#beyond">Beyond the screen</a>
          <a href="#approach">Approach</a>
          <a href="#commitment">Commitment</a>
          <a className="mw-nav__portal" href="/login">Log in <ArrowUpwardRoundedIcon aria-hidden="true" /></a>
        </nav>
      </header>

      <main id="top">
        <section className="mw-hero" aria-labelledby="hero-title">
          <div className="mw-hero__copy">
            <p className="mw-kicker"><AutoAwesomeRoundedIcon aria-hidden="true" /> Custom Software · Web Design · Tech Consulting</p>
            <h1 id="hero-title">We design and build software, websites, and technology solutions.</h1>
            <p className="mw-hero__subhead">From digital products to the hardware that supports them.</p>
            <p className="mw-hero__lede">From a simple website to a completely custom application, we turn ideas and challenges into technology built with precision, purpose, and excellence.</p>
            <a className="mw-button" href="#contact">Start a conversation <ArrowUpwardRoundedIcon aria-hidden="true" /></a>
          </div>
          <div className="mw-hero__visual" aria-label="MainzWare: software, web, and consulting" role="img">
            <div className="mw-hero__shine" />
            <img src={logoOnDark} alt="" className="mw-hero__logo" />
            <span className="mw-hero__divider" aria-hidden="true" />
            <ul className="mw-hero__pillars" aria-hidden="true">
              {pillars.map(({ icon: Icon, title }) => (
                <li key={title}><span className="mw-hero__pillar-icon"><Icon aria-hidden="true" /></span><span>{title}</span></li>
              ))}
            </ul>
          </div>
        </section>

        <section className="mw-services" id="services" aria-labelledby="services-title">
          <div className="mw-section-heading">
            <div><p className="mw-kicker">What we do</p><h2 id="services-title">One team.<br /><em>Three disciplines.</em></h2></div>
            <p>Everything we build starts with one of these three disciplines, working together as a single technology partner.</p>
          </div>
          <div className="mw-pillar-grid">
            {pillars.map(({ icon: Icon, number, title, items }) => (
              <article className="mw-pillar" key={title}>
                <div className="mw-pillar__top"><span>{number}</span><Icon aria-hidden="true" /></div>
                <h3>{title}</h3>
                <ul>
                  {items.map((item) => <li key={item}>{item}</li>)}
                </ul>
              </article>
            ))}
          </div>
        </section>

        <section className="mw-beyond" id="beyond" aria-labelledby="beyond-title">
          <div className="mw-beyond__grid">
            <div className="mw-beyond__heading">
              <HubRoundedIcon className="mw-beyond__watermark" aria-hidden="true" />
              <h2 id="beyond-title">Beyond<br /><em>the screen.</em></h2>
            </div>
            <div>
              <p>And when technology extends beyond the screen, we can help there too.</p>
              <p>Need a camera system? Setting up a network? Connecting buildings across a property or over distance? We design and implement the hardware, connectivity, and infrastructure that bring the entire technology solution together.</p>
            </div>
          </div>
          <ul className="mw-tags">
            {beyondTags.map(({ icon: Icon, label }) => (
              <li key={label}><span className="mw-tags__icon"><Icon aria-hidden="true" /></span><span>{label}</span></li>
            ))}
          </ul>
        </section>

        <section className="mw-approach" id="approach" aria-labelledby="approach-title">
          <p className="mw-kicker">How we work</p>
          <h2 id="approach-title">Our <em>approach.</em></h2>
          <p className="mw-approach__lede">We deliver reliable, secure, and scalable technology solutions tailored to your needs. From infrastructure to custom software, we help organizations streamline operations, improve efficiency, and achieve their goals with technology that works together.</p>
          <div className="mw-approach__steps">
            {approachSteps.map(({ number, title }, index) => (
              <div className="mw-approach__step" key={title}>
                <span>{number}</span>
                <h3>{title}</h3>
                {index < approachSteps.length - 1 && <span className="mw-approach__arrow" aria-hidden="true">→</span>}
              </div>
            ))}
          </div>
        </section>

        <section className="mw-commitment" id="commitment" aria-labelledby="commitment-title">
          <p className="mw-kicker">Why partner with us</p>
          <h2 id="commitment-title">Our <em>commitment.</em></h2>
          <p className="mw-approach__lede">We build long-term partnerships through trust, clear communication, and proven results. Our goal is to provide practical, secure, and future-ready solutions that help you maximize your technology investment and unlock new possibilities.</p>
          <ul className="mw-tags">
            {commitmentItems.map((label) => (
              <li key={label}><span className="mw-tags__icon"><CheckCircleRoundedIcon aria-hidden="true" /></span><span>{label}</span></li>
            ))}
          </ul>
        </section>

        <section className="mw-cta" id="contact" aria-labelledby="cta-title">
          <div><p className="mw-kicker">Let’s talk</p><h2 id="cta-title">Let’s build something<br /><em>exceptional.</em></h2></div>
          <div className="mw-cta__action"><p>Have an idea, a problem, or a technology project? Let’s talk about it.</p><a className="mw-button mw-button--light" href="mailto:hello@mainzware.com">Start a conversation <ArrowUpwardRoundedIcon aria-hidden="true" /></a></div>
        </section>
      </main>

      <footer className="mw-footer"><span>© {new Date().getFullYear()} MainzWare</span><span>Software with purpose.</span></footer>
    </div>
  );
}
