import Icon from '../../components/icon.js';
import './editor.scss';
import './style.scss';

const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;

registerBlockType( 'cgb/mrp-dynamic-block', {
	title: __( 'myRealPage - Dynamic' ),
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
				<h3>Dynamic Block</h3>
				<p>ReactJS editor with php render.</p>
			</div>
		);
	},
	save: () => {
		return null;
	},
} );
