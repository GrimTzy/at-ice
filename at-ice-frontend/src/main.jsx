import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import './a11y.css'
import './glow.css'
import './controls.css'
import App from './App.jsx'
import { installApiBase } from './lib/apiBase'
import { installSessionExpiryHandler } from './lib/sessionExpiry'
import { installValidationSound } from './lib/validationSound'
import { startPush } from './lib/push'
import { installPressFeedback } from './lib/pressFeedback'
import { startLiveFeed } from './lib/live'
import { installAutoCapitalize } from './lib/autoCapitalize'
import { installAutoGrow } from './lib/autoGrow'
import { installEffects } from './lib/fx'

// first: it must sit under the session-expiry wrapper, which sees the plain /api/... URLs
installApiBase()
installSessionExpiryHandler()
// a phone that is already signed in keeps its notices on (asks once, sends its address)
startPush(localStorage.getItem('token'))
installValidationSound()
installAutoCapitalize()
installAutoGrow()
installPressFeedback()
startLiveFeed()
// Optional motion layer (src/ui-effects/, switch in effectsConfig.js)
installEffects()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
