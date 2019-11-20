/**
 * BLOCK: mrp-block
 *
 * Registering a basic block with Gutenberg.
 */

//

import Icon from './icon.js';
import { Button } from '@wordpress/components';

//  Import CSS.
import './editor.scss';
import './style.scss';

const { RawHTML } = wp.element;
const { __ } = wp.i18n; // Import __() from wp.i18n
const { registerBlockType } = wp.blocks; // Import registerBlockType() from wp.blocks

/**
 * Register: aa Gutenberg Block.
 *
 * Registers a new block provided a unique name and an object defining its
 * behavior. Once registered, the block is made editor as an option to any
 * editor interface where blocks are implemented.
 *
 * @link https://wordpress.org/gutenberg/handbook/block-api/
 * @param  {string}   name     Block name.
 * @param  {Object}   settings Block settings.
 * @return {?WPBlock}          The block, if it has been successfully
 *                             registered; otherwise `undefined`.
 */

function mrpSCOptimalHeight() {
	let h = 500;
	if ( window.screen.availHeight > 600 ) {
		h = 700;
	}
	if ( window.screen.availHeight > 800 ) {
		h = 800;
	}
	return h;
}

function mrpOpenSC() {
	const win = window.open( 'https://private-office.myrealpage.com/wps/rest/auth/sc', 'mrp_shorcodes_wizard', 'scrollbars=1,width=800,height=' + mrpSCOptimalHeight() );
	if ( ! win ) {
		alert( 'It appears, you have blocked popups. Please allow popups for this page in order to open the Shortcode Wizard.' );
	} else {
		win.focus();
	}
	return false;
}

registerBlockType( 'cgb/block-mrp-block', {
	// Block name. Block names must be string that contains a namespace prefix. Example: my-plugin/my-custom-block.
	title: __( 'myRealPage - Shortcode' ), // Block title.
	icon: Icon, // Block icon from Dashicons → https://developer.wordpress.org/resource/dashicons/.
	category: 'mrp-blocks', // Block category — Group blocks together based on common traits E.g. common, formatting, layout widgets, embed.
	keywords: [ __( 'mrp-block — myRealPage Shortcode' ), __( 'mrp' ), __( 'myRealPage' ) ],
	attributes: {
		content: {
			type: 'string',
		},
	},

	/**
	 * The edit function describes the structure of your block in the context of the editor.
	 * This represents what the editor will render when the block is used.
	 *
	 * The "edit" property must be a valid function.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
	 *
	 * @param {Object} props Props.
	 * @returns {Mixed} JSX Component.
	 */
	edit: props => {
		function updateContent( data ) {
			props.setAttributes( { content: data } );
		}
		return (
			<div className={ props.className }>
				<Button isDefault onClick={ mrpOpenSC }>
					Retrieve myRealPage Shortcode
				</Button>
				<br />
				<b>Shorcode:</b>
				<br />
				<textarea rows="4" cols="50" onChange={ ( event ) => updateContent( event.target.value ) } value={ props.attributes.content } />
			</div>
		);
	},

	/**
	 * The save function defines the way in which the different attributes should be combined
	 * into the final markup, which is then serialized by Gutenberg into post_content.
	 *
	 * The "save" property must be specified and must be a valid function.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
	 *
	 * @param {Object} props Props.
	 * @returns {Mixed} JSX Frontend HTML.
	 */
	save: () => {
		return null;
	},
	// save: props => {
	// 	return (
	// 		<div className={ props.className }><RawHTML>{ props.attributes.content }</RawHTML></div>
	// 	);
	// },
} );
