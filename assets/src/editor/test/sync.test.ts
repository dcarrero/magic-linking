import { createInsertionLog } from '../insertions';
import { InsertionSync, MAX_LINKS } from '../sync';
import type { EditorHistoryResponse, EditorLink } from '../../types';

const base = { suggestion: 'abcd1234', target_id: 2, anchor: 'ancla' };

function setup( status: ( link: EditorLink ) => string = () => 'recorded' ) {
	const calls: EditorLink[][] = [];
	const log = createInsertionLog( 1, null );
	const sync = new InsertionSync( log, ( links ) => {
		calls.push( links );
		const response: EditorHistoryResponse = {
			batch_id: null,
			results: links.map( ( link ) => ( {
				insertion_id: link.insertion_id,
				status: status( link ) as 'recorded',
				change_id: 0,
			} ) ),
		};
		return Promise.resolve( response );
	} );
	return { calls, log, sync };
}

describe( 'InsertionSync', () => {
	it( 'al terminar un guardado envía solo lo que había al empezarlo', async () => {
		const { calls, log, sync } = setup();
		const first = log.add( base );
		sync.begin();
		const during = log.add( base ); // se añade mientras se guarda
		await sync.finish( false );

		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ]?.map( ( l ) => l.insertion_id ) ).toEqual( [
			first,
		] );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [
			during,
		] );
	} );

	it( 'no toca los not_found de inserciones posteriores a la foto', async () => {
		const { log, sync } = setup( () => 'not_found' );
		log.add( base );
		sync.begin();
		const later = log.add( base );
		await sync.finish( false );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [
			later,
		] );
	} );

	it( 'parte la lista en bloques del máximo del servidor', async () => {
		const { calls, log, sync } = setup();
		for ( let i = 0; i < MAX_LINKS + 5; i++ ) {
			log.add( base );
		}
		sync.begin();
		await sync.finish( false );
		expect( calls.map( ( c ) => c.length ) ).toEqual( [ MAX_LINKS, 5 ] );
		expect( log.list() ).toEqual( [] );
	} );

	it( 'tras un autoguardado conserva los not_found (puede no haber escrito la entrada)', async () => {
		const { log, sync } = setup( ( link ) =>
			link.anchor === 'ancla' ? 'not_found' : 'recorded'
		);
		const kept = log.add( base );
		log.add( { ...base, anchor: 'otra' } );
		sync.begin();
		await sync.finish( true );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [ kept ] );
	} );

	it( 'un error de red conserva la lista y el siguiente guardado la reintenta', async () => {
		const log = createInsertionLog( 1, null );
		let fail = true;
		const sent: number[] = [];
		const sync = new InsertionSync( log, ( links ) => {
			sent.push( links.length );
			if ( fail ) {
				return Promise.reject( new Error( 'red' ) );
			}
			return Promise.resolve( {
				batch_id: null,
				results: links.map( ( l ) => ( {
					insertion_id: l.insertion_id,
					status: 'recorded' as const,
					change_id: 1,
				} ) ),
			} );
		} );
		log.add( base );
		sync.begin();
		await sync.finish( false );
		expect( log.list() ).toHaveLength( 1 );
		fail = false;
		sync.begin();
		await sync.finish( false );
		expect( log.list() ).toHaveLength( 0 );
		expect( sent ).toEqual( [ 1, 1 ] );
	} );

	it( 'un 400 reintenta uno a uno y solo descarta el rechazado', async () => {
		const log = createInsertionLog( 1, null );
		const bad = log.add( { ...base, anchor: 'mala' } );
		const good = log.add( base );
		const sync = new InsertionSync( log, ( links ) => {
			if ( links.length > 1 || links[ 0 ]?.insertion_id === bad ) {
				return Promise.reject( { data: { status: 400 } } );
			}
			return Promise.resolve( {
				batch_id: null,
				results: [
					{
						insertion_id: good,
						status: 'recorded' as const,
						change_id: 1,
					},
				],
			} );
		} );
		sync.begin();
		await sync.finish( false );
		expect( log.list() ).toEqual( [] );
	} );

	it( 'un 403 o un 404 son definitivos: la entrada no existe o no se puede editar, no se reintenta para siempre', async () => {
		for ( const status of [ 403, 404 ] ) {
			const log = createInsertionLog( 1, null );
			log.add( base );
			const sync = new InsertionSync( log, () =>
				Promise.reject( { code: 'x', data: { status } } )
			);
			sync.begin();
			await sync.finish( false );
			expect( log.list() ).toHaveLength( 0 );
		}
	} );

	it( 'una nonce caducada (403 rest_cookie_invalid_nonce) conserva la lista', async () => {
		const log = createInsertionLog( 1, null );
		log.add( base );
		const sync = new InsertionSync( log, () =>
			Promise.reject( {
				code: 'rest_cookie_invalid_nonce',
				data: { status: 403 },
			} )
		);
		sync.begin();
		await sync.finish( false );
		expect( log.list() ).toHaveLength( 1 );
	} );

	it( 'un enlace deshecho antes de guardar no se envía', async () => {
		const { calls, log, sync } = setup();
		const id = log.add( base );
		sync.forget( id );
		sync.begin();
		await sync.finish( false );
		expect( calls ).toHaveLength( 0 );
	} );
} );
