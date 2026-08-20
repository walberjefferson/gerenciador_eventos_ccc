import { semearBanco } from './apoio';

/**
 * Preparo unico da suite: o banco volta ao mesmo ponto de partida antes do
 * primeiro cenario. Cada cenario usa um CPF proprio, entao a ordem entre eles
 * nao importa.
 */
export default function preparar(): void {
    semearBanco();
}
