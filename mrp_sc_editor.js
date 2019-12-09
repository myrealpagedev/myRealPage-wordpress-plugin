function mrp_sc_optimalHeight()
{
   var h = 500;
   if( screen.availHeight > 600 ) {
      h = 700;
   }
   if( screen.availHeight > 800 ) {
      h = 800;
   }
   return h;
}

function startPing( win ) {
	window.setInterval( function() {
		win.postMessage( '[mrp_wordpress_plugin ready]', '*' );
	}, 1000 );
}

function mrp_openSC()
{

	var privateOfficeUrl = 'https://private-office.myrealpage.com/wps/rest/auth/sc';

	if ( window.location.href.startsWith( 'http://192.' ) || window.location.href.startsWith( 'http://localhost' ) ) {
		privateOfficeUrl = 'http://localhost:8080/wps/rest/auth/sc';
	}

   var win = window.open( privateOfficeUrl, "mrp_shorcodes_wizard", "scrollbars=1,width=1024,height=" + mrp_sc_optimalHeight() );
   if( !win ) {
      alert( "It appears, you have blocked popups. Please allow popups for this page in order to open the Shortcode Wizard." );
   }
   else {
		win.focus();
		startPing( win );
   }
   return false;
}

