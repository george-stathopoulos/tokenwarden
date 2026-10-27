import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

function mount() {
	const root = document.getElementById( 'tokenwarden-root' );
	if ( ! root ) {
		return;
	}
	// The page markup ships its own .tokenwarden-root wrapper for the loading state; the app renders its own.
	root.classList.remove( 'tokenwarden-root' );
	createRoot( root ).render( <App /> );
}

// Render after every footer script has run.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
