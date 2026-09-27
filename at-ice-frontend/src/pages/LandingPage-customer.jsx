import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Snowflake, Truck, Award, Users, TrendingUp, CheckCircle, Clock,
  Mail, Phone, MapPin, ShoppingCart, Menu, X
} from 'lucide-react';

//  Button 
function Button({ variant = 'primary', size = 'md', className = '', children, ...props }) {
  const base = 'inline-flex items-center justify-center gap-2 rounded-lg transition-all duration-200 font-medium focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 focus:ring-offset-slate-950 disabled:opacity-50 disabled:cursor-not-allowed';
  const variants = {
    primary: 'bg-cyan-500 text-white hover:bg-cyan-600 active:bg-cyan-700 shadow-lg shadow-cyan-500/20 hover:shadow-cyan-500/40',
    secondary: 'bg-white/10 text-white hover:bg-white/20 backdrop-blur-sm border border-white/10',
    outline: 'border-2 border-cyan-500 text-cyan-500 hover:bg-cyan-500/10',
    ghost: 'text-white hover:bg-white/10',
  };
  const sizes = { sm: 'px-4 py-2 text-sm', md: 'px-6 py-3 text-base', lg: 'px-8 py-4 text-lg' };
  return (
    <button className={`${base} ${variants[variant]} ${sizes[size]} ${className}`} {...props}>
      {children}
    </button>
  );
}

// ─── Header 
function Header() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const navigate = useNavigate();

  const navItems = [
    { label: 'Home', href: '#' },
    { label: 'Products', href: '#products' },
    { label: 'Solutions', href: '#solutions' },
    { label: 'About Us', href: '#about' },
    { label: 'Contact', href: '#contact' },
  ];

  return (
    <header className="fixed top-0 left-0 right-0 z-50 bg-slate-950/80 backdrop-blur-md border-b border-white/10">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16 lg:h-20">

          {/* Logo */}
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 flex items-center justify-center">
              <Snowflake className="w-5 h-5 text-white" />
            </div>
            <div className="flex flex-col">
              <span className="text-white tracking-wider font-semibold">
                AT-<span className="text-cyan-400">ICE</span>
              </span>
              <span className="text-xs text-cyan-400/70 -mt-1">TUBE ICE SUPPLY</span>
            </div>
          </div>

          {/* Desktop Nav */}
          <nav className="hidden lg:flex items-center gap-8">
            {navItems.map((item, index) => (
              <a key={index} href={item.href} className="text-white/80 hover:text-white transition-colors relative group">
                {item.label}
                <span className="absolute -bottom-1 left-0 w-0 h-0.5 bg-cyan-400 group-hover:w-full transition-all duration-300"></span>
              </a>
            ))}
          </nav>

          {/* Actions */}
          <div className="flex items-center gap-3">
            <button className="relative p-2 text-white hover:bg-white/10 rounded-lg transition-colors">
              <ShoppingCart className="w-5 h-5" />
              <span className="absolute -top-1 -right-1 w-4 h-4 bg-cyan-500 rounded-full text-xs flex items-center justify-center">2</span>
            </button>
            <Button variant="outline" size="sm" className="hidden sm:inline-flex" onClick={() => navigate('/login')}>
              Login
            </Button>
            <button onClick={() => setMobileMenuOpen(!mobileMenuOpen)} className="lg:hidden p-2 text-white hover:bg-white/10 rounded-lg transition-colors">
              {mobileMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
            </button>
          </div>
        </div>

        {/* Mobile Nav */}
        {mobileMenuOpen && (
          <nav className="lg:hidden py-4 border-t border-white/10">
            {navItems.map((item, index) => (
              <a key={index} href={item.href} className="block py-2 text-white/80 hover:text-white hover:bg-white/5 px-4 rounded-lg transition-colors" onClick={() => setMobileMenuOpen(false)}>
                {item.label}
              </a>
            ))}
            <div className="mt-4 px-4">
              <Button variant="outline" size="sm" className="w-full" onClick={() => navigate('/login')}>
                Login
              </Button>
            </div>
          </nav>
        )}
      </div>
    </header>
  );
}

// ─── Hero ─────────────────────────────────────────────────────────────────────
function Hero() {
  const navigate = useNavigate();

  const features = [
    { icon: Award, label: 'Premium Quality', description: 'Pure and clean' },
    { icon: Truck, label: 'On-Time Delivery', description: 'Fast & Reliable' },
    { icon: Snowflake, label: 'Crystal Clear Ice', description: 'Highest quality' },
  ];

  const stats = [
    { value: '500+', label: 'Happy Customers', icon: Users },
    { value: '10,000+', label: 'Deliveries/month', icon: TrendingUp },
    { value: '99.8%', label: 'Satisfaction', icon: CheckCircle },
    { value: '15+', label: 'Years in Business', icon: Clock },
  ];

  return (
    <section className="relative min-h-screen flex items-center overflow-hidden">
      {/* Background */}
      <div className="absolute inset-0 bg-slate-950">
        <img src="/icetube.png" alt="bg" className="w-full h-full object-cover opacity-60" />
         <div className="absolute inset-0 bg-gradient-to-b from-slate-950/60 via-slate-950/80 to-slate-950"></div>
        <div className="absolute inset-0" style={{
          backgroundImage: `radial-gradient(circle at 20% 50%, rgba(6,182,212,0.08) 0%, transparent 50%),
                            radial-gradient(circle at 80% 20%, rgba(59,130,246,0.08) 0%, transparent 50%)`
        }}></div>
      </div>

      {/* Content */}
      <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 lg:py-32">
        <div className="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">

          {/* Left */}
          <div className="space-y-8">
            <div className="inline-flex items-center gap-2 px-4 py-2 bg-white/5 backdrop-blur-sm border border-white/10 rounded-full text-sm text-white/90">
              <Snowflake className="w-4 h-4 text-cyan-400" />
              <span>Premium Quality. Reliable Supply.</span>
            </div>

            <div className="space-y-4">
              <h1 className="text-4xl sm:text-5xl lg:text-6xl xl:text-7xl text-white leading-tight">
                Crystal Clear
                <br />
                <span className="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500">
                  Tube Ice
                </span>
              </h1>
              <p className="text-lg sm:text-xl text-white/70 max-w-xl">
                Supplying high-quality tube ice for businesses, restaurants, events, and industries.
              </p>
            </div>

            <div className="flex flex-wrap gap-3">
              {features.map((feature, index) => {
                const Icon = feature.icon;
                return (
                  <div key={index} className="flex items-center gap-2 px-4 py-2 bg-white/5 backdrop-blur-sm border border-white/10 rounded-lg hover:bg-white/10 transition-all duration-200 group">
                    <Icon className="w-5 h-5 text-cyan-400 group-hover:scale-110 transition-transform" />
                    <div className="flex flex-col">
                      <span className="text-white text-sm">{feature.label}</span>
                      <span className="text-white/50 text-xs">{feature.description}</span>
                    </div>
                  </div>
                );
              })}
            </div>

            <div className="flex flex-wrap gap-4">
              <Button size="lg" onClick={() => navigate('/login')}>
                Order Now
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
              </Button>
            </div>
          </div>

          {/* Right - Stats */}
          <div className="grid grid-cols-2 gap-4 sm:gap-6">
            {stats.map((stat, index) => {
              const Icon = stat.icon;
              return (
                <div key={index} className="p-6 bg-white/5 backdrop-blur-sm border border-white/10 rounded-xl hover:bg-white/10 hover:border-cyan-400/50 transition-all duration-300 group">
                  <Icon className="w-8 h-8 text-cyan-400 mb-4 group-hover:scale-110 transition-transform" />
                  <div className="text-3xl sm:text-4xl text-white mb-1">{stat.value}</div>
                  <div className="text-sm text-white/60">{stat.label}</div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Scroll Indicator */}
      <div className="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
        <div className="w-6 h-10 border-2 border-white/30 rounded-full flex justify-center pt-2">
          <div className="w-1.5 h-3 bg-cyan-400 rounded-full"></div>
        </div>
      </div>
    </section>
  );
}

// ─── Footer ───────────────────────────────────────────────────────────────────
function Footer() {
  const footerLinks = {
    Products: ['Tube Ice', 'Block Ice', 'Crushed Ice', 'Custom Solutions'],
    Company: ['About Us', 'Our Team', 'Careers', 'Contact'],
    Support: ['FAQs', 'Shipping Info', 'Returns', 'Terms of Service'],
    Resources: ['Blog', 'Case Studies', 'Industry News', 'Downloads'],
  };

  return (
    <footer className="bg-slate-950 border-t border-white/10">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 lg:py-16">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-8 lg:gap-12">

          {/* Brand */}
          <div className="lg:col-span-2">
            <div className="flex items-center gap-2 mb-4">
              <div className="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 flex items-center justify-center">
                <Snowflake className="w-6 h-6 text-white" />
              </div>
              <div className="flex flex-col">
                <span className="text-white text-lg tracking-wider font-semibold">
                  AT-<span className="text-cyan-400">ICE</span>
                </span>
                <span className="text-xs text-cyan-400/70">TUBE ICE SUPPLY</span>
              </div>
            </div>
            <p className="text-white/60 mb-6 max-w-sm">
              Supplying high-quality tube ice for businesses, restaurants, events, and industries. Crystal clear quality, on-time delivery.
            </p>
            <div className="space-y-3">
              <a href="tel:+1234567890" className="flex items-center gap-2 text-white/60 hover:text-cyan-400 transition-colors group">
                <Phone className="w-4 h-4 group-hover:scale-110 transition-transform" />
                <span>+1 (234) 567-890</span>
              </a>
              <a href="mailto:info@atice.com" className="flex items-center gap-2 text-white/60 hover:text-cyan-400 transition-colors group">
                <Mail className="w-4 h-4 group-hover:scale-110 transition-transform" />
                <span>info@atice.com</span>
              </a>
              <div className="flex items-start gap-2 text-white/60">
                <MapPin className="w-4 h-4 mt-1 flex-shrink-0" />
                <span>Alexandra Tube Ice Inc., Philippines</span>
              </div>
            </div>
          </div>

          {/* Links */}
          {Object.entries(footerLinks).map(([title, links]) => (
            <div key={title}>
              <h3 className="text-white mb-4 font-medium">{title}</h3>
              <ul className="space-y-3">
                {links.map((link) => (
                  <li key={link}>
                    <a href="#" className="text-white/60 hover:text-cyan-400 transition-colors inline-block hover:translate-x-1 duration-200">
                      {link}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-12 pt-8 border-t border-white/10 flex items-center justify-center">
          <p className="text-white/40 text-sm">© 2026 AT-ICE. All rights reserved.</p>
        </div>
      </div>
    </footer>
  );
}

// ─── Main Export ──────────────────────────────────────────────────────────────
export default function LandingPageCustomer() {
  return (
    <div className="min-h-screen bg-slate-950">
      <Header />
      <main>
        <Hero />
      </main>
      <Footer />
    </div>
  );
}