import { act, StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { CurrentLink } from '../../types';
import { api } from '../../api';
import type { EditorAdapter } from '../adapters/types';
import { outboundSignature, useCurrentLinks } from '../useCurrentLinks';

jest.mock( '../../api', () => ( { api: { currentLinks: jest.fn() } } ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const guide: CurrentLink = { id: 7, title: 'Guía', anchors: [ 'guía' ] };
const bomba: CurrentLink = { id: 9, title: 'Bomba', anchors: [ 'bomba' ] };
const makeAdapter = () =>
	( {
		postId: 5,
		getContent: () => '<p>texto</p>',
		getTitle: () => 'Título',
	} ) as unknown as EditorAdapter;
const adapter = makeAdapter();

let root: Root;
let host: HTMLElement;
let shown: number[] = [];

function Probe( {
	initial,
	signature,
	editor = adapter,
}: {
	initial: CurrentLink[];
	signature: string;
	editor?: EditorAdapter;
} ) {
	shown = useCurrentLinks( editor, initial, signature ).map(
		( link ) => link.id
	);
	return null;
}

const render = ( initial: CurrentLink[], signature: string ) =>
	act( async () => {
		root.render( <Probe initial={ initial } signature={ signature } /> );
	} );
const settle = () =>
	act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );
	} );

beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	( api.currentLinks as jest.Mock ).mockReset();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'useCurrentLinks', () => {
	const initial = [ guide ];

	it( 'starts with what the server sent and asks for nothing', async () => {
		await render( initial, '' );
		await settle();

		expect( shown ).toEqual( [ 7 ] );
		expect( api.currentLinks ).not.toHaveBeenCalled();
	} );

	it( 'asks the server again after a link is added and after it is undone, so undo removes it', async () => {
		( api.currentLinks as jest.Mock ).mockResolvedValueOnce( {
			current_links: [ guide, bomba ],
		} );
		await render( initial, '' );
		await render( initial, 'a' );
		await settle();
		expect( shown ).toEqual( [ 7, 9 ] );
		expect( api.currentLinks ).toHaveBeenCalledWith(
			5,
			'<p>texto</p>',
			'Título',
			expect.any( AbortSignal )
		);

		( api.currentLinks as jest.Mock ).mockResolvedValueOnce( {
			current_links: [ guide ],
		} );
		await render( initial, '' );
		await settle();
		expect( shown ).toEqual( [ 7 ] );
		expect( api.currentLinks ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'goes back to the new server list when the suggestions are loaded again', async () => {
		( api.currentLinks as jest.Mock ).mockResolvedValueOnce( {
			current_links: [ guide, bomba ],
		} );
		await render( initial, '' );
		await render( initial, 'a' );
		await settle();

		await render( [ bomba ], 'a' );
		await settle();

		expect( shown ).toEqual( [ 9 ] );
		expect( api.currentLinks ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not lose the request when the effect repeats before it is sent (new editor object, strict mode)', async () => {
		( api.currentLinks as jest.Mock ).mockResolvedValue( {
			current_links: [ guide, bomba ],
		} );
		await act( async () => {
			root.render(
				<StrictMode>
					<Probe initial={ initial } signature="" />
				</StrictMode>
			);
		} );
		await act( async () => {
			root.render(
				<StrictMode>
					<Probe initial={ initial } signature="a" />
				</StrictMode>
			);
		} );
		// Antes de los 300 ms el editor cambia de objeto y el efecto se repite.
		await act( async () => {
			root.render(
				<StrictMode>
					<Probe
						initial={ initial }
						signature="a"
						editor={ makeAdapter() }
					/>
				</StrictMode>
			);
		} );
		await settle();

		expect( api.currentLinks ).toHaveBeenCalled();
		expect( shown ).toEqual( [ 7, 9 ] );
	} );
} );

describe( 'outboundSignature', () => {
	it( 'changes with the links added in this entry and ignores the inbound ones', () => {
		const added = { kind: 'added' };
		expect(
			outboundSignature(
				{
					'5:9:3:bomba': added,
					'5:2:1:guia': { kind: 'dismissed' },
					'77:5:0:otra': added,
				},
				5
			)
		).toBe( '5:9:3:bomba' );
		expect( outboundSignature( { '77:5:0:otra': added }, 5 ) ).toBe( '' );
	} );
} );
