// Zona fija (no UTC) para las pruebas JS: si una fecha del servidor se leyera como hora local, se vería otra hora.
// globalSetup corre antes de crear los workers, así que la variable se propaga.
module.exports = async () => {
	process.env.TZ = 'Asia/Tokyo';
};
