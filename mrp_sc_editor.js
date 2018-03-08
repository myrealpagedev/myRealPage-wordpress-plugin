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

function mrp_openSC()
{
   var win = window.open( "http://listings.myrealpage.com/wps/rest/auth/sc", "mrp_shorcodes_wizard", "scrollbars=1,width=800,height=" + mrp_sc_optimalHeight() );
   if( !win ) {
      alert( "It appears, you have blocked popups. Please allow popups for this page in order to open the Shortcode Wizard." );
   }
   else {
      win.focus();
   }
   return false;
}

