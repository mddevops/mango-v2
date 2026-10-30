import { motion, useReducedMotion } from 'framer-motion';
import { useEffect, useId, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

type AnimatedGridPatternProps = {
    width?: number;
    height?: number;
    x?: number;
    y?: number;
    strokeDasharray?: number | string;
    numSquares?: number;
    className?: string;
    maxOpacity?: number;
    duration?: number;
    repeatDelay?: number;
};

type Square = { id: number; pos: [number, number] };

export function AnimatedGridPattern({
    width = 40,
    height = 40,
    x = -1,
    y = -1,
    strokeDasharray = 0,
    numSquares = 50,
    className,
    maxOpacity = 0.5,
    duration = 4,
    repeatDelay = 0.5,
}: AnimatedGridPatternProps) {
    const id = useId();
    const containerRef = useRef<SVGSVGElement>(null);
    const reduceMotion = useReducedMotion();
    const [dimensions, setDimensions] = useState({ width: 0, height: 0 });
    const [squares, setSquares] = useState<Square[]>([]);

    useEffect(() => {
        if (!dimensions.width || !dimensions.height) {
            return;
        }

        const position = (): [number, number] => [
            Math.floor((Math.random() * dimensions.width) / width),
            Math.floor((Math.random() * dimensions.height) / height),
        ];

        setSquares(
            Array.from({ length: numSquares }, (_, index) => ({
                id: index,
                pos: position(),
            })),
        );
    }, [dimensions, numSquares, width, height]);

    useEffect(() => {
        const element = containerRef.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver((entries) => {
            for (const entry of entries) {
                setDimensions({
                    width: entry.contentRect.width,
                    height: entry.contentRect.height,
                });
            }
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const moveSquare = (squareId: number) =>
        setSquares((current) =>
            current.map((square) =>
                square.id === squareId
                    ? {
                          ...square,
                          pos: [
                              Math.floor(
                                  (Math.random() * dimensions.width) / width,
                              ),
                              Math.floor(
                                  (Math.random() * dimensions.height) / height,
                              ),
                          ],
                      }
                    : square,
            ),
        );

    return (
        <svg
            ref={containerRef}
            aria-hidden="true"
            className={cn(
                'pointer-events-none absolute inset-0 h-full w-full fill-gray-400/30 stroke-gray-400/30',
                className,
            )}
        >
            <defs>
                <pattern
                    id={id}
                    width={width}
                    height={height}
                    patternUnits="userSpaceOnUse"
                    x={x}
                    y={y}
                >
                    <path
                        d={`M.5 ${height}V.5H${width}`}
                        fill="none"
                        strokeDasharray={strokeDasharray}
                    />
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill={`url(#${id})`} />
            {!reduceMotion && (
                <svg x={x} y={y} className="overflow-visible">
                    {squares.map(({ pos: [squareX, squareY], id: squareId }, index) => (
                        <motion.rect
                            initial={{ opacity: 0 }}
                            animate={{ opacity: maxOpacity }}
                            transition={{
                                duration,
                                repeat: 1,
                                delay: index * 0.1,
                                repeatDelay,
                                repeatType: 'reverse',
                            }}
                            onAnimationComplete={() => moveSquare(squareId)}
                            key={`${squareX}-${squareY}-${index}`}
                            width={width - 1}
                            height={height - 1}
                            x={squareX * width + 1}
                            y={squareY * height + 1}
                            fill="currentColor"
                            strokeWidth="0"
                        />
                    ))}
                </svg>
            )}
        </svg>
    );
}

export default AnimatedGridPattern;
