import { createInsertionLog, newInsertionId, shortKey } from '../insertions';

function memory() {
	const data = new Map< string, string >();
	return {
		data,
		getItem: ( key: string ) => data.get( key ) ?? null,
		setItem: ( key: string, value: string ) => void data.set( key, value ),
		removeItem: ( key: string ) => void data.delete( key ),
	};
}

const link = { suggestion: '1:2:10:ancla', target_id: 2, anchor: 'ancla' };

describe( 'newInsertionId', () => {
	it( 'cumple el patrón del servidor y no se repite', () => {
		const ids = new Set( Array.from( { length: 50 }, newInsertionId ) );
		expect( ids.size ).toBe( 50 );
		ids.forEach( ( id ) =>
			expect( id ).toMatch( /^[A-Za-z0-9_-]{8,64}$/ )
		);
	} );
} );

describe( 'createInsertionLog', () => {
	it( 'apunta los enlaces con un identificador cada uno', () => {
		const log = createInsertionLog( 7, memory() );
		const a = log.add( link );
		const b = log.add( link );
		expect( a ).not.toBe( b );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [ a, b ] );
	} );

	it( 'los enlaces deshechos en el editor no cuentan', () => {
		const log = createInsertionLog( 7, memory() );
		const a = log.add( link );
		const b = log.add( link );
		log.remove( a );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [ b ] );
	} );

	it( 'clear quita los ya tratados por el servidor y deja el resto', () => {
		const log = createInsertionLog( 7, memory() );
		const a = log.add( link );
		const b = log.add( link );
		log.clear( [ a ] );
		expect( log.list().map( ( l ) => l.insertion_id ) ).toEqual( [ b ] );
	} );

	it( 'sobrevive a una recarga (editor clásico) y se vacía del almacenamiento', () => {
		const storage = memory();
		const first = createInsertionLog( 7, storage );
		const id = first.add( link );

		const reloaded = createInsertionLog( 7, storage );
		expect( reloaded.list().map( ( l ) => l.insertion_id ) ).toEqual( [
			id,
		] );
		expect( createInsertionLog( 8, storage ).list() ).toEqual( [] );

		reloaded.clear( [ id ] );
		expect( storage.data.size ).toBe( 0 );
	} );

	it( 'ignora un almacenamiento corrupto o ausente', () => {
		const storage = memory();
		storage.setItem( 'magiclinking:editor-links:7', '{no es json' );
		expect( createInsertionLog( 7, storage ).list() ).toEqual( [] );

		storage.setItem( 'magiclinking:editor-links:7', '[{"x":1},3]' );
		expect( createInsertionLog( 7, storage ).list() ).toEqual( [] );

		const none = createInsertionLog( 7, null );
		none.add( link );
		expect( none.list() ).toHaveLength( 1 );
	} );

	it( 'no falla si el almacenamiento lanza', () => {
		const broken = {
			getItem: () => {
				throw new Error( 'no' );
			},
			setItem: () => {
				throw new Error( 'no' );
			},
			removeItem: () => {
				throw new Error( 'no' );
			},
		};
		const log = createInsertionLog( 7, broken );
		const id = log.add( link );
		expect( log.list() ).toHaveLength( 1 );
		log.remove( id );
		expect( log.list() ).toHaveLength( 0 );
	} );
} );

describe( 'sitios distintos', () => {
	it( 'la misma entrada en otro sitio (multisitio por subdirectorio) no comparte la cuenta', () => {
		const storage = memory();
		const a = createInsertionLog(
			7,
			storage,
			'/sitio-a/wp-admin/admin-ajax.php'
		);
		const b = createInsertionLog(
			7,
			storage,
			'/sitio-b/wp-admin/admin-ajax.php'
		);
		a.add( link );
		expect( b.list() ).toEqual( [] );
		expect(
			createInsertionLog(
				7,
				storage,
				'/sitio-a/wp-admin/admin-ajax.php'
			).list()
		).toHaveLength( 1 );
	} );
} );

describe( 'shortKey', () => {
	it( 'es corta, estable y no lleva el ancla entera', () => {
		const long = '1:2:10:' + 'palabra '.repeat( 50 );
		expect( shortKey( long ) ).toMatch( /^[0-9a-f]{8,16}$/ );
		expect( shortKey( long ) ).toBe( shortKey( long ) );
		expect( shortKey( long ) ).not.toBe( shortKey( long + 'x' ) );
	} );
} );
