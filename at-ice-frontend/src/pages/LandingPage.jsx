function LandingPage() {
  return (
    <div className="min-h-screen" style={{backgroundColor: '#F8FAFC'}}>
      
      {/* Navbar */}
      <nav className="flex items-center justify-between px-10 py-4 shadow-md" style={{backgroundColor: '#225597'}}>
        <div className="flex items-center gap-3">
          <span className="text-white text-2xl font-bold">AT-ICE</span>
          <span className="text-blue-200 text-sm">Alexandra Tube Ice</span>
        </div>
        <div className="flex gap-8 text-white text-sm">
          <a href="#about" className="hover:text-blue-200 transition">About</a>
          <a href="#products" className="hover:text-blue-200 transition">Products</a>
          <a href="#contact" className="hover:text-blue-200 transition">Contact</a>
        </div>
         <a href="/login">
         <button className="bg-blue-400 hover:bg-blue-300 text-white font-bold px-6 py-2 rounded-lg transition">
          Login
          </button>
           </a>
       </nav>

      <hr className="border-0 h-[4px] bg-blue-400"/>

      {/* Hero Section */}
      <div className="flex flex-col items-center justify-center text-center py-32 px-6" 
           style={{backgroundColor: '#0B1E3C'}}>
        <h1 className="text-5xl font-bold text-white mb-4">
          Welcome to AT-ICE
        </h1>
        <p className="text-blue-200 text-xl mb-2">
          Alexandra Tube Ice Manufacturing
        </p>
        <p className="text-blue-200 text-md max-w-xl mb-8">
          Your trusted supplier of quality tube ice for commercial and industrial use in Luzon.
        </p>
        <a href="/login">
         <button className="bg-blue-400 hover:bg-blue-300 text-white font-bold px-10 py-3 rounded-lg text-lg transition">
            Get Started
           </button>
        </a>
      </div>

      <hr className="border-0 h-[4px] bg-blue-400"/>

      {/* About Section */}
      <div id="about" className="bg-white py-16 px-10 text-center">
        <h2 className="text-3xl font-bold mb-4" style={{color: '#1E3A5F'}}>About Us</h2>
        <div className="w-16 h-1 mx-auto mb-6 rounded" style={{backgroundColor: '#0B1E3C'}}></div>
        <p className="text-gray-800 max-w-2xl mx-auto leading-relaxed">
          AT-ICE Alexandra Tube Ice Manufacturing is a leading ice producer 
          serving over 300 commercial clients across Luzon. We deliver 
          quality tube ice using a first-in, first-out (FIFO) approach 
          to ensure freshness and quality.
        </p>
      </div>

      <hr className="border-0 h-[4px] bg-blue-400"/>

      {/* Products Section */}
      <div id="products" className="py-16 px-10 text-center" style={{backgroundColor: '#1E3A5F'}}>
        <h2 className="text-3xl font-bold mb-2 text-white">Our Products</h2>
        <div className="w-16 h-1 mx-auto mb-8 rounded bg-blue-400"></div>
        <div className="flex justify-center gap-8 flex-wrap">
          <div className="bg-white rounded-xl p-8 w-64 shadow-md hover:shadow-lg transition">
            <div className="text-5xl mb-4">🧊</div>
            <h3 className="font-bold text-xl mb-2" style={{color: '#1E3A5F'}}>Tube Ice</h3>
            <p className="text-gray-800 text-sm leading-relaxed">
              Premium quality tube ice for commercial and industrial use.
            </p>
          </div>
          <div className="bg-white rounded-xl p-8 w-64 shadow-md hover:shadow-lg transition">
            <div className="text-5xl mb-4">📦</div>
            <h3 className="font-bold text-xl mb-2" style={{color: '#1E3A5F'}}>Bulk Orders</h3>
            <p className="text-gray-800 text-sm leading-relaxed">
              Large volume orders for supermarkets, restaurants, and stores.
            </p>
          </div>
          <div className="bg-white rounded-xl p-8 w-64 shadow-md hover:shadow-lg transition">
            <div className="text-5xl mb-4">🚚</div>
            <h3 className="font-bold text-xl mb-2" style={{color: '#1E3A5F'}}>Delivery</h3>
            <p className="text-gray-800 text-sm leading-relaxed">
              Fast and reliable delivery across Luzon with real-time tracking.
            </p>
          </div>
        </div>
      </div>

      <hr className="border-0 h-[4px] bg-blue-400"/>

      {/* Contact Section */}
      <div id="contact" className="py-16 px-10 text-center bg-white">
        <h2 className="text-3xl font-bold mb-2" style={{color: '#1E3A5F'}}>Contact Us</h2>
        <div className="w-16 h-1 mx-auto mb-6 rounded" style={{backgroundColor: '#1E3A5F'}}></div>
        <p className="text-gray-800 mb-2">📍 San Jose del Monte, Bulacan</p>
        <p className="text-gray-800 mb-2">📞 0916-625-0633</p>
        <p className="text-gray-800">✉️ atice@email.com</p>
      </div>

      <hr className="border-0 h-[4px] bg-blue-400"/>

      {/* Footer */}
      <div className="py-6 text-center" style={{backgroundColor: '#225597'}}>
        <p className="text-blue-200 text-sm">
          © 2026 AT-ICE Alexandra Tube Ice Manufacturing. All rights reserved.
        </p>
      </div>

    </div>
  )
}

export default LandingPage