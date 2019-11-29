import Icon from '../../components/icon.js';
import './editor.scss';
import './style.scss';

const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;

registerBlockType( 'cgb/mrp-helloworld-block', {
	title: __( 'myRealPage - HelloWorld' ),
	icon: Icon,
	category: 'mrp-blocks',
	keywords: [ __( 'mrp-blocks — myRealPage HelloWorld' ), __( 'mrp' ), __( 'myRealPage' ) ],
	attributes: {
		content: {
			type: 'string',
		},
	},
	edit: props => {
		return (
			<div className={ props.className }>
				<h3>HelloWorld</h3>
				<p>ReactJS editor and render.</p>
			</div>
		);
	},
	save: () => {
		return (
			<div>HelloWorld</div>
		);
	},
} );
