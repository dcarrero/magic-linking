import { autosaveWritesPost } from '../autosave';

describe( 'autosaveWritesPost', () => {
	it( 'escribe la entrada solo en un borrador del propio usuario (como hace WordPress)', () => {
		expect( autosaveWritesPost( 'draft', 5, 5 ) ).toBe( true );
		expect( autosaveWritesPost( 'auto-draft', 5, 5 ) ).toBe( true );
	} );

	it( 'no la escribe en un borrador ajeno, en una pendiente ni en una publicada', () => {
		expect( autosaveWritesPost( 'draft', 5, 6 ) ).toBe( false );
		expect( autosaveWritesPost( 'pending', 5, 5 ) ).toBe( false );
		expect( autosaveWritesPost( 'publish', 5, 5 ) ).toBe( false );
		expect( autosaveWritesPost( 'future', 5, 5 ) ).toBe( false );
	} );

	it( 'sin usuario conocido no cuenta', () => {
		expect( autosaveWritesPost( 'draft', 5, 0 ) ).toBe( false );
	} );
} );
